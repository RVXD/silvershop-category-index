# SilverShop Category Index

A derived, read-only flat index of **product → effective category** membership for SilverShop, so category
filtering is a single indexed JOIN instead of an `OR` across two relations plus a per-level tree walk.

## The problem

A SilverShop product's **primary** category is its page-tree parent (`ParentID`); **secondary** categories live in
the editable `ProductCategories` many_many. So there is no single relation you can query/index for "all products in
category X". Core merges the two at runtime:

- `Product::getCategoryIDs()` / `getCategories()` — ancestors (`ParentID` chain) + `ProductCategories`.
- `ProductCategory::ProductsShowable()` — `filterAny(['ParentID' => …, 'ProductCategories.ID' => …])` plus, for the
  recursive default, `AllChildCategoryIDs()` (one query per tree level).

That's fine for in-app rendering, but you can't hand it to SOLR / a report / a raw SQL query, and on large catalogs
or deep trees the recursive listing does more work than it needs to.

## What this adds

- A flat, read-only many_many **`Product.EffectiveCategories`** holding the product's primary category, all its
  ancestor categories, and its secondary `ProductCategories`. Because it contains the **ancestors**, filtering on a
  category's own ID already includes every descendant — no tree walk.
- The editable `ProductCategories` stays the single source of truth; this relation is derived and disposable.

```php
// all products in this category (incl. descendants), one indexed JOIN:
$products = Product::get()->filter(['EffectiveCategories.ID' => $category->ID]);
```

With `optimise_products_showable` on (default), `ProductCategory::ProductsShowable()` is transparently routed through
the index for recursive listings, so the storefront gets the speed-up for free.

## Maintenance

- **Product write/publish** → that one product's index is rebuilt inline (cheap).
- **Category move** (`ParentID` change) → every product in the subtree is reindexed, because their ancestor set
  changed. This runs **inline** here; on a large catalog it can touch thousands of products, so install
  `symbiote/silverstripe-queuedjobs` and push `CategoryStructureExtension::reindexSubtree()` to a job (see the note
  in that class). This is the part Werner Krauss's "add if missing in `onAfterWrite`" proposal gets wrong — it never
  *removes* stale rows on a move.
- **Backfill / repair** → `dev/tasks/SilverShop-CategoryIndex-RebuildCategoryIndexTask`.

## Config

```yaml
SilverShop\CategoryIndex\CategoryStructureExtension:
  optimise_products_showable: true   # route ProductsShowable() through the index (recursive listings only)
```

The `ProductsShowable()` swap only applies when `ProductCategory.include_child_groups` is on (the recursive model the
index matches). Turn `optimise_products_showable` off to keep core's query while still using the index directly.

## Status

Prototype — see silvershop/silvershop-core#804.

## Requirements

- silvershop/core ^6, silverstripe/framework ^6, PHP ^8.3
