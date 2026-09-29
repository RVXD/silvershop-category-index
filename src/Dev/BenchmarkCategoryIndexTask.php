<?php

namespace SilverShop\CategoryIndex\Dev;

use SilverShop\CategoryIndex\CategoryStructureExtension;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Dev/benchmark helper — times the recursive product list for the "Benchmark Catalog" root three ways:
 *   1. OLD    core ProductsShowable() (ParentID|ProductCategories OR + AllChildCategoryIDs tree walk)
 *   2. NEW    ProductsShowable() with the index swap on (tree walk still runs, product query is the index join)
 *   3. DIRECT Product::get()->filter(EffectiveCategories.ID) — what SOLR/custom code would use (no tree walk)
 * Run SeedLargeCatalogTask first.
 */
class BenchmarkCategoryIndexTask extends BuildTask
{
    protected string $title = 'Category index: benchmark ProductsShowable old vs new vs direct';

    protected static string $description =
        'Times the recursive product list on the Benchmark Catalog root: core vs index-swap vs direct index query.';

    private const ITERATIONS = 25;

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(ProductCategory::class, 'include_child_groups', true);

        $root = ProductCategory::get()->filter('URLSegment', 'benchmark-catalog')->first();
        if (!$root) {
            $output->writeln('No Benchmark Catalog — run SeedLargeCatalogTask first.');
            return Command::FAILURE;
        }

        // Realistic storefront pattern: one paginated page (LIMIT) + the total count for the pager.
        Config::modify()->set(CategoryStructureExtension::class, 'optimise_products_showable', false);
        $oldCount = $root->ProductsShowable()->count();
        $oldPageMs = $this->time(fn() => $root->ProductsShowable()->limit(12)->column('ID'));
        $oldCountMs = $this->time(fn() => $root->ProductsShowable()->count());
        $oldFullMs = $this->time(fn() => $root->ProductsShowable()->column('ID'));

        Config::modify()->set(CategoryStructureExtension::class, 'optimise_products_showable', true);
        $newCount = $root->ProductsShowable()->count();
        $newPageMs = $this->time(fn() => $root->ProductsShowable()->limit(12)->column('ID'));
        $newCountMs = $this->time(fn() => $root->ProductsShowable()->count());
        $newFullMs = $this->time(fn() => $root->ProductsShowable()->column('ID'));

        $output->writeln(sprintf(
            "Recursive products under '%s': old=%d new=%d (match: %s)",
            $root->Title,
            $oldCount,
            $newCount,
            $oldCount === $newCount ? 'YES' : 'NO'
        ));
        $output->writeln('                                    OLD (core)   NEW (index override)');
        $output->writeln(sprintf('  page of 12 (paginated fetch)   : %8.2f ms   %8.2f ms   (%.2fx)', $oldPageMs, $newPageMs, $oldPageMs / max($newPageMs, 0.0001)));
        $output->writeln(sprintf('  count() (pager total)          : %8.2f ms   %8.2f ms   (%.2fx)', $oldCountMs, $newCountMs, $oldCountMs / max($newCountMs, 0.0001)));
        $output->writeln(sprintf('  full list (all %-4d rows)      : %8.2f ms   %8.2f ms   (%.2fx)', $oldCount, $oldFullMs, $newFullMs, $oldFullMs / max($newFullMs, 0.0001)));

        return Command::SUCCESS;
    }

    private function time(callable $fn): float
    {
        $fn(); // warm up
        $start = microtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $fn();
        }

        return (microtime(true) - $start) / self::ITERATIONS * 1000;
    }
}
