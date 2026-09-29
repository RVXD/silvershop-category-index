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
 * Dev/benchmark helper — seeds a 3-level category tree with products under a "Benchmark Catalog" root
 * (draft only) so category-filter performance can be measured. First run builds the tree + INITIAL_PER_LEAF
 * products; each subsequent run APPENDS BATCH_PRODUCTS more, distributed across the leaves (so you can grow the
 * catalog and re-benchmark). Delete the "Benchmark Catalog" page to reset. Not part of the shipped module surface.
 */
class SeedLargeCatalogTask extends BuildTask
{
    protected string $title = 'Category index: seed / grow a large benchmark catalog';

    protected static string $description =
        'Builds a deep category tree + products under "Benchmark Catalog" (draft); re-run to append more products.';

    private const TOP = 4;
    private const MID = 4;
    private const LEAF = 3;
    private const INITIAL_PER_LEAF = 30;
    private const BATCH_PRODUCTS = 2000;

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        Versioned::set_stage(Versioned::DRAFT);
        ProductCategoryIndexExtension::$disable_auto_reindex = true;

        $root = ProductCategory::get()->filter('URLSegment', 'benchmark-catalog')->first();
        if (!$root) {
            [$root, $leafIds] = $this->buildTree();
            $target = self::TOP * self::MID * self::LEAF * self::INITIAL_PER_LEAF;
            $output->writeln("Built tree: " . (1 + self::TOP + self::TOP * self::MID + count($leafIds)) . ' categories.');
        } else {
            $leafIds = ProductCategory::get()->filter('URLSegment:StartsWith', 'bench-leaf-')->column('ID');
            $target = self::BATCH_PRODUCTS;
        }

        $existing = Product::get()->filter('URLSegment:StartsWith', 'bench-product-')->count();

        $new = [];
        for ($i = 1; $i <= $target; $i++) {
            $n = $existing + $i;
            $product = Product::create();
            $product->Title = "Bench Product $n";
            $product->URLSegment = "bench-product-$n";
            $product->ParentID = $leafIds[($n - 1) % count($leafIds)];
            $product->BasePrice = 9.99;
            $product->write();
            $product->publishSingle();
            $new[] = $product;
        }

        ProductCategoryIndexExtension::$disable_auto_reindex = false;
        foreach ($new as $product) {
            $product->rebuildCategoryIndex();
        }

        $total = Product::get()->filter('URLSegment:StartsWith', 'bench-product-')->count();
        $output->writeln("Added {$target} products (total bench products now {$total}); index built for the new ones.");

        return Command::SUCCESS;
    }

    /**
     * @return array{0: ProductCategory, 1: int[]}
     */
    private function buildTree(): array
    {
        $root = $this->makeCategory('Benchmark Catalog', 'benchmark-catalog', 0);
        $leafIds = [];

        for ($t = 1; $t <= self::TOP; $t++) {
            $top = $this->makeCategory("Bench Top $t", "bench-top-$t", $root->ID);
            for ($m = 1; $m <= self::MID; $m++) {
                $mid = $this->makeCategory("Bench Mid $t-$m", "bench-mid-$t-$m", $top->ID);
                for ($l = 1; $l <= self::LEAF; $l++) {
                    $leaf = $this->makeCategory("Bench Leaf $t-$m-$l", "bench-leaf-$t-$m-$l", $mid->ID);
                    $leafIds[] = $leaf->ID;
                }
            }
        }

        return [$root, $leafIds];
    }

    private function makeCategory(string $title, string $segment, int $parentId): ProductCategory
    {
        $category = ProductCategory::create();
        $category->Title = $title;
        $category->URLSegment = $segment;
        $category->ParentID = $parentId;
        $category->write();
        $category->publishSingle();

        return $category;
    }
}
