<?php

namespace App\Repository;

use App\Entity\Product;
use PDO;

/**
 * Reads products from the catalog. all() is intentionally naive for the
 * profiling demo: one query per product (a classic N+1). top-functions
 * fingers PDO::query; callers traces the cost back here.
 */
class ProductRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Load every product in the catalog.
     *
     * @return Product[]
     */
    public function all(): array
    {
        $ids = $this->db->query('SELECT id FROM products')->fetchAll(PDO::FETCH_COLUMN);

        $products = [];
        foreach ($ids as $id) {
            $products[] = $this->find((int) $id);
        }

        return $products;
    }
}
