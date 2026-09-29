<?php

declare(strict_types=1);

namespace SilverShop\CategoryIndex;

use SilverShop\Model\Order;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Reports\Report;

/**
 * Sales grouped by product category (including descendant categories), for a period. Uses the EffectiveCategories
 * index, so each product line rolls up to its category and every ancestor category in a single JOIN — no tree walk.
 * Because category totals include their whole subtree, category rows overlap and the grand total is NOT the sum of
 * rows (a sale in a leaf also counts toward every parent category). Product line totals only (excludes shipping/tax
 * modifiers); placed orders only.
 */
class SalesByCategoryReport extends Report
{
    public function title()
    {
        return _t(__CLASS__ . '.TITLE', 'Sales by category');
    }

    public function description()
    {
        return _t(
            __CLASS__ . '.DESC',
            'Revenue and units sold per category (including descendant categories) for a period. Category totals include their subtree, so rows overlap.'
        );
    }

    public function group()
    {
        return _t('SilverShop\\Reports.GROUP', 'Shop');
    }

    public function sort()
    {
        return 310;
    }

    public function parameterFields()
    {
        return FieldList::create(
            DateField::create('StartDate', _t(__CLASS__ . '.Start', 'From (paid date)')),
            DateField::create('EndDate', _t(__CLASS__ . '.End', 'To (paid date)'))
        );
    }

    public function sourceRecords($params = null)
    {
        $statuses = array_values((array) Order::config()->get('placed_status'));
        if (empty($statuses)) {
            return ArrayList::create();
        }

        $query = SQLSelect::create();
        $query->setSelect([
            'Category' => '"st"."Title"',
            'Units' => 'SUM("oi"."Quantity")',
            'Revenue' => 'SUM("oa"."CalculatedTotal")',
        ]);
        $query->setFrom('"SilverShop_OrderAttribute" AS "oa"');
        $query->addInnerJoin('SilverShop_OrderItem', '"oi"."ID" = "oa"."ID"', 'oi');
        $query->addInnerJoin('SilverShop_Product_OrderItem', '"poi"."ID" = "oa"."ID"', 'poi');
        $query->addInnerJoin('SilverShop_Order', '"o"."ID" = "oa"."OrderID"', 'o');
        $query->addInnerJoin('SilverShop_Product_EffectiveCategories', '"ec"."SilverShop_ProductID" = "poi"."ProductID"', 'ec');
        $query->addInnerJoin('SiteTree', '"st"."ID" = "ec"."SilverShop_ProductCategoryID"', 'st');

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $query->addWhere(['"o"."Status" IN (' . $placeholders . ')' => $statuses]);

        if (!empty($params['StartDate'])) {
            $query->addWhere(['"o"."Paid" >= ?' => $params['StartDate'] . ' 00:00:00']);
        }
        if (!empty($params['EndDate'])) {
            $query->addWhere(['"o"."Paid" <= ?' => $params['EndDate'] . ' 23:59:59']);
        }

        $query->setGroupBy(['"ec"."SilverShop_ProductCategoryID"', '"st"."Title"']);
        $query->setOrderBy('SUM("oa"."CalculatedTotal")', 'DESC');

        $list = ArrayList::create();
        foreach ($query->execute() as $row) {
            $list->push(ArrayData::create([
                'Category' => $row['Category'],
                'Units' => (int) $row['Units'],
                'Revenue' => number_format((float) $row['Revenue'], 2),
            ]));
        }

        return $list;
    }

    public function columns()
    {
        return [
            'Category' => _t(__CLASS__ . '.ColCategory', 'Category'),
            'Units' => _t(__CLASS__ . '.ColUnits', 'Units sold'),
            'Revenue' => _t(__CLASS__ . '.ColRevenue', 'Revenue'),
        ];
    }
}
