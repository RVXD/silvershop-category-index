<?php

namespace SilverShop\CategoryIndex\Tests;

use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Exercises the derived EffectiveCategories index end-to-end against the Live stage: the publish hook builds it,
 * an ancestor-category filter reaches descendant products, the index matches core's own recursive membership,
 * and ProductsShowable() is routed through the index.
 *
 * Category tree:  root ─┬─ apparel ── shirts
 *                       └─ electronics
 * Products:  tshirt  primary=shirts,       secondary=[electronics]
 *            gadget  primary=electronics
 */
class CategoryIndexTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    protected ProductCategory $root;
    protected ProductCategory $apparel;
    protected ProductCategory $shirts;
    protected ProductCategory $electronics;
    protected Product $tshirt;
    protected Product $gadget;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(ProductCategory::class, 'must_have_price', false);
        Config::modify()->set(Product::class, 'global_allow_purchase', true);

        // Categories first (parents before children), so each product publishes against a complete Live tree.
        $this->root = $this->makeCategory('Root');
        $this->apparel = $this->makeCategory('Apparel', $this->root->ID);
        $this->shirts = $this->makeCategory('Shirts', $this->apparel->ID);
        $this->electronics = $this->makeCategory('Electronics', $this->root->ID);

        $this->tshirt = Product::create(['Title' => 'T-Shirt', 'BasePrice' => 19.99, 'ParentID' => $this->shirts->ID]);
        $this->tshirt->write();
        $this->tshirt->ProductCategories()->add($this->electronics);
        $this->tshirt->publishSingle();

        $this->gadget = Product::create(['Title' => 'Gadget', 'BasePrice' => 49.99, 'ParentID' => $this->electronics->ID]);
        $this->gadget->write();
        $this->gadget->publishSingle();
    }

    private function makeCategory(string $title, int $parentId = 0): ProductCategory
    {
        $category = ProductCategory::create(['Title' => $title, 'ParentID' => $parentId]);
        $category->write();
        $category->publishSingle();

        return $category;
    }

    /**
     * @return int[]
     */
    private function productIdsInCategory(int $categoryId): array
    {
        $ids = array_map('intval', Product::get()->filter('EffectiveCategories.ID', $categoryId)->column('ID'));
        sort($ids);

        return $ids;
    }

    public function testPublishBuildsEffectiveCategories(): void
    {
        $effective = array_map('intval', array_values($this->tshirt->EffectiveCategories()->getIDList()));
        sort($effective);

        // primary (shirts) + ancestors (apparel, root) + secondary (electronics)
        $expected = [$this->root->ID, $this->apparel->ID, $this->shirts->ID, $this->electronics->ID];
        sort($expected);

        $this->assertSame($expected, $effective);
    }

    public function testFilterByAncestorReturnsDescendantProducts(): void
    {
        $tshirt = (int) $this->tshirt->ID;
        $gadget = (int) $this->gadget->ID;
        $both = [min($tshirt, $gadget), max($tshirt, $gadget)];

        // The whole point: an ancestor category reaches a product that only lives in a descendant — no tree walk.
        $this->assertSame([$tshirt], $this->productIdsInCategory((int) $this->apparel->ID));
        $this->assertSame([$tshirt], $this->productIdsInCategory((int) $this->shirts->ID));

        // Secondary (ProductCategories) membership is indexed alongside the primary branch.
        $this->assertSame($both, $this->productIdsInCategory((int) $this->electronics->ID));

        // Root reaches the whole catalogue.
        $this->assertSame($both, $this->productIdsInCategory((int) $this->root->ID));
    }

    public function testIndexMatchesCoreRecursiveMembership(): void
    {
        foreach (ProductCategory::get() as $category) {
            $subtree = array_merge([$category->ID], $category->AllChildCategoryIDs());

            $expected = array_values(array_unique(array_map('intval', Product::get()->filterAny([
                'ParentID' => $subtree,
                'ProductCategories.ID' => $subtree,
            ])->column('ID'))));
            sort($expected);

            $this->assertSame($expected, $this->productIdsInCategory((int) $category->ID), $category->Title);
        }
    }

    public function testUnpublishClearsIndex(): void
    {
        $tshirtId = (int) $this->tshirt->ID;

        $this->tshirt->doUnpublish();

        $this->assertCount(0, $this->tshirt->EffectiveCategories());
        $this->assertNotContains($tshirtId, $this->productIdsInCategory((int) $this->apparel->ID));
    }

    public function testRebuildIsIdempotent(): void
    {
        $before = array_map('intval', array_values($this->tshirt->EffectiveCategories()->getIDList()));
        sort($before);

        $this->tshirt->rebuildCategoryIndex();

        $after = array_map('intval', array_values($this->tshirt->EffectiveCategories()->getIDList()));
        sort($after);

        $this->assertSame($before, $after);
    }

    public function testProductsShowableRoutedThroughIndex(): void
    {
        Config::modify()->set(ProductCategory::class, 'include_child_groups', true);

        $ids = array_map('intval', $this->apparel->ProductsShowable()->column('ID'));
        sort($ids);

        // Recursive listing on an ancestor category returns the descendant product via the index.
        $this->assertSame([(int) $this->tshirt->ID], $ids);
    }
}
