<?php

namespace SilverShop\CategoryIndex\Dev;

use SilverShop\CategoryIndex\ProductCategoryIndexExtension;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Dev/benchmark helper — seeds a 3-level category tree with many products under a "Benchmark Catalog" root
 * (draft only) so category-filter performance can be measured. Idempotent: skips if the root already exists.
 * Delete the "Benchmark Catalog" page to reseed. Not part of the shipped module surface.
 */
class SeedLargeCatalogTask extends BuildTask
{
    protected string $title = 'Category index: seed a large benchmark catalog';

    protected static string $description =
        'Seeds a deep category tree + ~1440 products under "Benchmark Catalog" (draft) for benchmarking.';

    private const TOP = 4;
    private const MID = 4;
    private const LEAF = 3;
    private const PRODUCTS_PER_LEAF = 30;

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        Versioned::set_stage(Versioned::DRAFT);

        if (ProductCategory::get()->filter('URLSegment', 'benchmark-catalog')->exists()) {
            $output->writeln('Benchmark Catalog already exists — skipping. Delete that page to reseed.');
            return Command::SUCCESS;
        }

        ProductCategoryIndexExtension::$disable_auto_reindex = true;

        $root = $this->makeCategory('Benchmark Catalog', 'benchmark-catalog', 0);
        $categories = 1;
        $leafIds = [];

        for ($t = 1; $t <= self::TOP; $t++) {
            $top = $this->makeCategory("Bench Top $t", "bench-top-$t", $root->ID);
            $categories++;
            for ($m = 1; $m <= self::MID; $m++) {
                $mid = $this->makeCategory("Bench Mid $t-$m", "bench-mid-$t-$m", $top->ID);
                $categories++;
                for ($l = 1; $l <= self::LEAF; $l++) {
                    $leaf = $this->makeCategory("Bench Leaf $t-$m-$l", "bench-leaf-$t-$m-$l", $mid->ID);
                    $categories++;
                    $leafIds[] = $leaf->ID;
                }
            }
        }

        $products = 0;
        foreach ($leafIds as $leafId) {
            for ($p = 1; $p <= self::PRODUCTS_PER_LEAF; $p++) {
                $products++;
                $product = Product::create();
                $product->Title = "Bench Product $products";
                $product->URLSegment = "bench-product-$products";
                $product->ParentID = $leafId;
                $product->BasePrice = 9.99;
                $product->write();
            }
        }

        ProductCategoryIndexExtension::$disable_auto_reindex = false;

        $reindexed = 0;
        foreach (Product::get() as $product) {
            $product->rebuildCategoryIndex();
            $reindexed++;
        }

        $output->writeln("Seeded {$categories} categories and {$products} products (draft); rebuilt index for {$reindexed} products.");

        return Command::SUCCESS;
    }

    private function makeCategory(string $title, string $segment, int $parentId): ProductCategory
    {
        $category = ProductCategory::create();
        $category->Title = $title;
        $category->URLSegment = $segment;
        $category->ParentID = $parentId;
        $category->write();

        return $category;
    }
}
