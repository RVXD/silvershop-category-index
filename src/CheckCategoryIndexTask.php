<?php

namespace SilverShop\CategoryIndex;

use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Consistency check: for every category, compares the flat-index recursive membership
 * (EffectiveCategories.ID = cat) against core's own recursive logic (filterAny over ParentID +
 * ProductCategories.ID across the category subtree). Reports any drift. Exit code non-zero on mismatch.
 */
class CheckCategoryIndexTask extends BuildTask
{
    protected string $title = 'Category index: verify the index matches core recursive membership';

    protected static string $description =
        'Compares EffectiveCategories filtering against core ParentID/ProductCategories recursion for every category.';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $checked = 0;
        $mismatches = 0;

        foreach (ProductCategory::get() as $category) {
            $subtree = array_merge([$category->ID], $category->AllChildCategoryIDs());

            $expected = Product::get()->filterAny([
                'ParentID' => $subtree,
                'ProductCategories.ID' => $subtree,
            ])->column('ID');
            $expected = array_values(array_unique(array_map('intval', $expected)));
            sort($expected);

            $actual = Product::get()->filter(['EffectiveCategories.ID' => $category->ID])->column('ID');
            $actual = array_values(array_unique(array_map('intval', $actual)));
            sort($actual);

            $checked++;
            if ($expected !== $actual) {
                $mismatches++;
                $output->writeln(sprintf(
                    'MISMATCH category #%d "%s": core=[%s] index=[%s]',
                    $category->ID,
                    $category->Title,
                    implode(',', $expected),
                    implode(',', $actual)
                ));
            }
        }

        $output->writeln(sprintf('Checked %d categories, %d mismatch(es).', $checked, $mismatches));

        // Confirm the ProductsShowable() swap is actually wired (by-ref reassignment took effect).
        $sample = ProductCategory::get()->filter('ParentID:GreaterThan', 0)->first() ?: ProductCategory::get()->first();
        if ($sample) {
            $params = [];
            $sql = $sample->ProductsShowable()->dataQuery()->query()->sql($params);
            $usesIndex = str_contains($sql, 'EffectiveCategories');
            $output->writeln('ProductsShowable() routed through index: ' . ($usesIndex ? 'YES' : 'NO'));
            if (!$usesIndex) {
                $output->writeln('  ' . $sql);
            }
        }

        return $mismatches === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
