<?php

namespace SilverShop\CategoryIndex;

use SilverShop\Page\Product;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Rebuilds the derived EffectiveCategories index for every product (primary + ancestors + secondary).
 * Use for the initial backfill after install, or to repair drift. Idempotent.
 */
class RebuildCategoryIndexTask extends BuildTask
{
    protected string $title = 'Category index: rebuild effective categories for all products';

    protected static string $description =
        'Rebuilds the derived EffectiveCategories index (primary + ancestors + secondary) for every product. Idempotent.';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $count = 0;

        foreach (Product::get() as $product) {
            $product->rebuildCategoryIndex();
            $count++;
        }

        $output->writeln("Rebuilt effective-category index for {$count} product(s).");

        return Command::SUCCESS;
    }
}
