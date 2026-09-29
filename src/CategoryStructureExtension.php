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
     * Early hook (silvershop/core with the overrideProductsShowable hook): supply the whole list up front so
     * core skips the AllChildCategoryIDs() tree walk and the ParentID/ProductCategories query entirely. This is
     * where the real speed-up comes from — a single indexed lookup, no walk. Only applies to recursive listings
     * (the index bakes in descendants via ancestor rows); non-recursive calls fall through to core.
     */
    public function overrideProductsShowable(&$override, $recursive): void
    {
        if (!Config::inst()->get(self::class, 'optimise_products_showable')) {
            return;
        }
        if (empty($recursive) || !ProductCategory::config()->get('include_child_groups')) {
            return;
        }

        $override = $this->indexedProducts();
    }

    /**
     * Fallback for a core WITHOUT the overrideProductsShowable hook: swap the already-built list for the indexed
     * one. Correct, but runs after the tree walk so it does not save that cost. No-op once
     * overrideProductsShowable has already short-circuited.
     */
    public function updateProductsShowable(SS_List &$products): void
    {
        if (!Config::inst()->get(self::class, 'optimise_products_showable')) {
            return;
        }
        if (!ProductCategory::config()->get('include_child_groups')) {
            return;
        }

        $products = $this->indexedProducts();
    }

    /**
     * The single-JOIN indexed product list for this category (incl. descendants), preserving core's
     * must_have_price behaviour.
     */
    private function indexedProducts(): SS_List
    {
        $products = Product::get()->filter(['EffectiveCategories.ID' => $this->getOwner()->ID]);

        if (ProductCategory::config()->get('must_have_price')) {
            if (Product::has_extension(ProductVariationsExtension::class)) {
                $products = $products->filterAny([
                    'BasePrice:GreaterThan' => 0,
                    'Variations.Price:GreaterThan' => 0,
                ]);
            } else {
                $products = $products->filter(['BasePrice:GreaterThan' => 0]);
            }
        }

        return $products;
    }
}
