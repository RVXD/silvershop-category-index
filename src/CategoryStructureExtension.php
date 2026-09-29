<?php

namespace SilverShop\CategoryIndex;

use SilverShop\Extension\ProductVariationsExtension;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Model\List\SS_List;

/**
 * Applied to {@link \SilverShop\Page\ProductCategory}.
 *
 * 1. Keeps the product index consistent when the tree changes: moving a category (ParentID change) shifts the
 *    ancestor set of every product beneath it, so the whole subtree is reindexed.
 * 2. Optionally routes ProductCategory::ProductsShowable() through the flat index (one indexed JOIN) instead of
 *    the ParentID-OR-many_many filter plus the per-level AllChildCategoryIDs() walk.
 *
 * @property ProductCategory $owner
 */
class CategoryStructureExtension extends Extension
{
    /**
     * Swap ProductsShowable() to the flat index. Only equivalent for recursive listings
     * (ProductCategory.include_child_groups on) — the storefront default — because the index bakes in
     * descendants via ancestor rows. Set false to always keep core's own query.
     */
    private static bool $optimise_products_showable = true;

    public function onAfterWrite(): void
    {
        $owner = $this->getOwner();

        // A move (new parent) changes the ancestor set of every product in the subtree -> reindex them.
        if ($owner->isInDB() && $owner->isChanged('ParentID')) {
            self::reindexSubtree($owner);
        }
    }

    /**
     * Reindex every product in this category and all descendant categories.
     *
     * NOTE: a large subtree can touch thousands of products — too slow to do in a web request. With
     * symbiote/silverstripe-queuedjobs installed, push this to a QueuedJob; here it runs inline, which is
     * fine for dev and small catalogs. See README.
     */
    public static function reindexSubtree(ProductCategory $category): void
    {
        $categoryIds = array_merge([$category->ID], $category->AllChildCategoryIDs());

        $products = Product::get()->filterAny([
            'ParentID' => $categoryIds,
            'ProductCategories.ID' => $categoryIds,
        ]);

        foreach ($products as $product) {
            $product->rebuildCategoryIndex();
        }
    }

    /**
     * Replace the product list with a single indexed lookup on the flat relation. The index already contains
     * ancestor rows, so filtering on this category's own ID returns it plus every descendant — no ParentID/
     * many_many OR and no AllChildCategoryIDs() tree walk.
     */
    public function updateProductsShowable(SS_List &$products): void
    {
        $owner = $this->getOwner();

        if (!Config::inst()->get(self::class, 'optimise_products_showable')) {
            return;
        }
        if (!ProductCategory::config()->get('include_child_groups')) {
            return;
        }

        $optimised = Product::get()->filter(['EffectiveCategories.ID' => $owner->ID]);

        // Preserve core's must_have_price behaviour.
        if (ProductCategory::config()->get('must_have_price')) {
            if (Product::has_extension(ProductVariationsExtension::class)) {
                $optimised = $optimised->filterAny([
                    'BasePrice:GreaterThan' => 0,
                    'Variations.Price:GreaterThan' => 0,
                ]);
            } else {
                $optimised = $optimised->filter(['BasePrice:GreaterThan' => 0]);
            }
        }

        $products = $optimised;
    }
}
