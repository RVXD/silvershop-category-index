<?php

namespace SilverShop\CategoryIndex;

use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Core\Extension;

/**
 * Applied to {@link \SilverShop\Page\Product}.
 *
 * Maintains a derived, read-only flat many_many "EffectiveCategories" holding every category a product
 * effectively belongs to: its primary (tree ParentID) category, all ancestor categories, and its explicit
 * secondary ProductCategories. This is the queryable/indexable relation the editable ProductCategories isn't —
 * a single JOIN answers "all products in category X (including descendants)" without the ParentID-OR-many_many
 * filter and the per-level child-category walk.
 *
 * The relation is rebuilt from ProductCategories + tree ancestors on every product write/publish; it is NOT
 * user-editable. Rebuild the whole set with
 * dev/tasks/SilverShop-CategoryIndex-RebuildCategoryIndexTask.
 *
 * @property Product $owner
 */
class ProductCategoryIndexExtension extends Extension
{
    private static array $many_many = [
        'EffectiveCategories' => ProductCategory::class,
    ];

    public function onAfterWrite(): void
    {
        $this->rebuildCategoryIndex();
    }

    public function onAfterPublish($original = null): void
    {
        $this->rebuildCategoryIndex();
    }

    /**
     * Recompute this single product's effective-category set. Cheap — safe to run inline on write.
     * getCategories() already returns the effective set (ancestors + secondary, real categories only),
     * so we simply mirror it into the flat relation, skipping the write when nothing changed.
     */
    public function rebuildCategoryIndex(): void
    {
        $owner = $this->getOwner();
        if (!$owner->isInDB()) {
            return;
        }

        $wanted = array_values($owner->getCategories()->getIDList());
        $current = array_values($owner->EffectiveCategories()->getIDList());

        sort($wanted);
        sort($current);
        if ($wanted === $current) {
            return;
        }

        $owner->EffectiveCategories()->setByIDList($wanted);
    }
}
