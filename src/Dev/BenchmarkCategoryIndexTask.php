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

        Config::modify()->set(CategoryStructureExtension::class, 'optimise_products_showable', false);
        $oldCount = $root->ProductsShowable()->count();
        $oldMs = $this->time(fn() => $root->ProductsShowable()->column('ID'));

        Config::modify()->set(CategoryStructureExtension::class, 'optimise_products_showable', true);
        $newCount = $root->ProductsShowable()->count();
        $newMs = $this->time(fn() => $root->ProductsShowable()->column('ID'));

        $rootId = $root->ID;
        $directCount = Product::get()->filter(['EffectiveCategories.ID' => $rootId])->count();
        $directMs = $this->time(fn() => Product::get()->filter(['EffectiveCategories.ID' => $rootId])->column('ID'));

        $output->writeln(sprintf(
            "Recursive products under '%s': old=%d new=%d direct=%d (match: %s)",
            $root->Title,
            $oldCount,
            $newCount,
            $directCount,
            ($oldCount === $newCount && $newCount === $directCount) ? 'YES' : 'NO'
        ));
        $output->writeln(sprintf('OLD    core ProductsShowable (OR + tree walk) : %6.2f ms/run', $oldMs));
        $output->writeln(sprintf('NEW    ProductsShowable via index swap        : %6.2f ms/run', $newMs));
        $output->writeln(sprintf('DIRECT index filter (no tree walk)            : %6.2f ms/run', $directMs));
        if ($newMs > 0) {
            $output->writeln(sprintf('Speedup  new vs old: %.2fx   direct vs old: %.2fx', $oldMs / $newMs, $oldMs / max($directMs, 0.0001)));
        }

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
