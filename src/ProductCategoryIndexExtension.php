<?php

namespace SilverShop\CategoryIndex;

use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Versioned\Versioned;

/**
 * Applied to {@link \SilverShop\Page\Product}.
 *
 * Maintains a derived, read-only flat many_many "EffectiveCategories" holding every category a product
 * effectively belongs to: its primary (tree ParentID) category, all ancestor categories, and its explicit
 * secondary ProductCategories. A single JOIN then answers "all products in category X (including descendants)".
 *
 * The index reflects the **published (Live) stage**, because the storefront reads Live — so it is (re)built on
 * publish and cleared on unpublish. Draft-only edits do not touch it. It is NOT user-editable. Rebuild the whole
 * set with dev/tasks/SilverShop-CategoryIndex-RebuildCategoryIndexTask.
 *
 * @property Product $owner
 */
class ProductCategoryIndexExtension extends Extension
{
    private static array $many_many = [
        'EffectiveCategories' => ProductCategory::class,
    ];

    /**
     * Set true around a bulk import/seed to skip the per-write reindex, then rebuild once
     * (RebuildCategoryIndexTask). Avoids O(n) index churn while publishing many products.
     */
    public static bool $disable_auto_reindex = false;

    /**
     * The effective-category index is derived and rebuilt automatically on publish — it must never be edited by
     * hand, so keep its auto-scaffolded relation field out of the CMS.
     */
    public function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName('EffectiveCategories');
    }

    public function onAfterPublish($original = null): void
    {
        if (self::$disable_auto_reindex) {
            return;
        }
        $this->rebuildCategoryIndex();
    }

    public function onAfterUnpublish(): void
    {
        if (self::$disable_auto_reindex) {
            return;
        }
        // The Live version is gone, so rebuildCategoryIndex() will clear the relation.
        $this->rebuildCategoryIndex();
    }

    /**
     * Recompute this product's effective-category set from the Live stage. Cheap — safe to run on publish.
     */
    public function rebuildCategoryIndex(): void
    {
        $owner = $this->getOwner();
        if (!$owner->isInDB()) {
            return;
        }

        $wanted = $this->liveCategoryIdsFor((int) $owner->ID);
        $current = array_map('intval', array_values($owner->EffectiveCategories()->getIDList()));

        sort($wanted);
        sort($current);
        if ($wanted === $current) {
            return;
        }

        if ($wanted === []) {
            $owner->EffectiveCategories()->removeAll();
            return;
        }

        $owner->EffectiveCategories()->setByIDList($wanted);
    }

    /**
     * Effective category IDs for a product as published (Live). Empty when the product is not on Live.
     * Reads in an isolated Live reading mode so it is correct regardless of the caller's current stage.
     * getCategories() is ProductCategory::get()->byIDs(...); byIDs([]) throws "empty set" in SS6, so it is
     * only called when there are category IDs.
     *
     * @return int[]
     */
    private function liveCategoryIdsFor(int $productId): array
    {
        return Versioned::withVersionedMode(function () use ($productId): array {
            Versioned::set_stage(Versioned::LIVE);

            $live = Product::get()->byID($productId);
            if (!$live) {
                return [];
            }

            $categoryIds = $live->getCategoryIDs();

            return empty($categoryIds)
                ? []
                : array_map('intval', array_values($live->getCategories()->getIDList()));
        });
    }
}
