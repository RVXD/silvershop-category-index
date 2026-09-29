<?php

namespace SilverShop\CategoryIndex\Dev;

use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Dev helper — removes everything SeedLargeCatalogTask created (the "Benchmark Catalog" tree + its products).
 */
class DeleteBenchmarkCatalogTask extends BuildTask
{
    protected string $title = 'Category index: delete the benchmark catalog';

    protected static string $description = 'Deletes the "Benchmark Catalog" tree and all bench products created for benchmarking.';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        Versioned::set_stage(Versioned::DRAFT);

        $products = 0;
        foreach (Product::get()->filter('URLSegment:StartsWith', 'bench-product-') as $product) {
            $product->EffectiveCategories()->removeAll();
            $product->deleteFromStage(Versioned::LIVE);
            $product->delete();
            $products++;
        }

        $categories = 0;
        // Deepest first (higher IDs were created later) so no page is deleted while it still has children.
        foreach (ProductCategory::get()->filter('URLSegment:StartsWith', 'bench')->sort('ID', 'DESC') as $category) {
            $category->deleteFromStage(Versioned::LIVE);
            $category->delete();
            $categories++;
        }

        $output->writeln("Deleted {$products} bench products and {$categories} bench categories.");

        return Command::SUCCESS;
    }
}
