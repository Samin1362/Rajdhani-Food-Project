<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `products` and its three child tables (doc §8.5, RTPP-19).
 *
 * One repository for four tables, not four repositories, because they are one
 * aggregate: nothing outside this module ever queries `product_pack_sizes`,
 * `product_highlights` or `product_images` except by joining through
 * `product_id`, and nothing else holds a foreign key onto their `id` columns —
 * unlike `categories`, there is no other table whose correctness depends on a
 * pack size row surviving with the same id (`product_enquiries.pack_size_label`
 * is a plain text snapshot, not a reference). That is what makes
 * delete-and-reinsert safe for the children here, in a way it would not be
 * for a seeded table something else points at.
 *
 * Every mutating method assumes it is called inside a transaction the service
 * controls — this class never calls `beginTransaction()` itself, so it can be
 * composed into the single all-or-nothing write the DoD requires.
 */
final class ProductRepository extends Repository
{
    private const PRODUCT_COLUMNS = 'id, category_id, name, slug, short_description, tagline,
                                     description, ingredients, nutrition_info, brewing_guide,
                                     packaging_info, key_features, badge_text, badge_color,
                                     status, is_featured, sort_order, view_count,
                                     rating_average, rating_count, meta_title, meta_description,
                                     created_at, updated_at, deleted_at';

    // ─── products ────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function paginate(int $limit, int $offset, ?string $search, ?string $status, ?string $categoryId): array
    {
        [$where, $parameters] = $this->listFilter($search, $status, $categoryId);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            'SELECT ' . self::PRODUCT_COLUMNS . " FROM products {$where}
              ORDER BY sort_order, name
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function count(?string $search, ?string $status, ?string $categoryId): int
    {
        [$where, $parameters] = $this->listFilter($search, $status, $categoryId);

        return (int) $this->scalar("SELECT COUNT(*) FROM products {$where}", $parameters);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one(
            'SELECT ' . self::PRODUCT_COLUMNS . ' FROM products WHERE id = :id AND deleted_at IS NULL',
            [':id' => $id],
        );
    }

    public function slugExists(string $slug, ?string $excludingId = null): bool
    {
        $sql = 'SELECT id FROM products WHERE slug = :slug';
        $parameters = [':slug' => $slug];

        if ($excludingId !== null) {
            $sql .= ' AND id != :excluding';
            $parameters[':excluding'] = $excludingId;
        }

        return $this->scalar($sql . ' LIMIT 1', $parameters) !== null;
    }

    public function categoryExists(string $categoryId): bool
    {
        return $this->scalar(
            'SELECT id FROM categories WHERE id = :id AND deleted_at IS NULL',
            [':id' => $categoryId],
        ) !== null;
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $now = $this->now();

        $row = $fields + [
            'id' => $id, 'view_count' => 0, 'rating_average' => '0.0', 'rating_count' => 0,
            'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
        ];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO products (' . implode(', ', array_map($this->quote(...), $columns)) . ')
             VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')',
            $row,
        );

        return $id;
    }

    /** @param array<string,scalar|null> $fields */
    public function update(string $id, array $fields): int
    {
        if ($fields === []) {
            return 0;
        }

        $assignments = [];
        $parameters = [':id' => $id, ':updated_at' => $this->now()];

        foreach ($fields as $column => $value) {
            $assignments[] = $this->quote($column) . ' = :' . $column;
            $parameters[':' . $column] = $value;
        }

        return $this->run(
            'UPDATE products SET ' . implode(', ', $assignments) . ', updated_at = :updated_at
              WHERE id = :id AND deleted_at IS NULL',
            $parameters,
        );
    }

    /** Idempotent, like categories — see CategoryRepository for why. */
    public function softDelete(string $id): int
    {
        $now = $this->now();

        return $this->run(
            'UPDATE products SET deleted_at = :deleted_at, updated_at = :updated_at
              WHERE id = :id AND deleted_at IS NULL',
            [':deleted_at' => $now, ':updated_at' => $now, ':id' => $id],
        );
    }

    /**
     * @param list<string> $orderedIds
     *
     * @return list<string> ids that do not exist
     */
    public function reorder(array $orderedIds): array
    {
        $missing = [];
        $position = 0;

        foreach ($orderedIds as $id) {
            $position++;
            $affected = $this->run(
                'UPDATE products SET sort_order = :position, updated_at = :now
                  WHERE id = :id AND deleted_at IS NULL',
                [':position' => $position, ':now' => $this->now(), ':id' => $id],
            );

            if ($affected === 0) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    // ─── pack sizes ──────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function packSizes(string $productId): array
    {
        return $this->all(
            'SELECT id, product_id, label, sku, price, compare_price, discount_percent,
                    price_includes_vat, is_default, is_available, sort_order
               FROM product_pack_sizes WHERE product_id = :product_id
              ORDER BY sort_order, label',
            [':product_id' => $productId],
        );
    }

    /** @return array<string,mixed>|null */
    public function findPackSize(string $productId, string $id): ?array
    {
        return $this->one(
            'SELECT id, product_id, label, sku, price, compare_price, discount_percent,
                    price_includes_vat, is_default, is_available, sort_order
               FROM product_pack_sizes WHERE id = :id AND product_id = :product_id',
            [':id' => $id, ':product_id' => $productId],
        );
    }

    public function skuExists(string $sku, ?string $excludingId = null): bool
    {
        $sql = 'SELECT id FROM product_pack_sizes WHERE sku = :sku';
        $parameters = [':sku' => $sku];

        if ($excludingId !== null) {
            $sql .= ' AND id != :excluding';
            $parameters[':excluding'] = $excludingId;
        }

        return $this->scalar($sql . ' LIMIT 1', $parameters) !== null;
    }

    /** @param array<string,scalar|null> $fields */
    public function createPackSize(string $productId, array $fields): string
    {
        $id = UlidHelper::generate();
        $row = $fields + ['id' => $id, 'product_id' => $productId];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO product_pack_sizes (' . implode(', ', array_map($this->quote(...), $columns)) . ')
             VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')',
            $row,
        );

        return $id;
    }

    /** @param array<string,scalar|null> $fields */
    public function updatePackSize(string $id, array $fields): int
    {
        if ($fields === []) {
            return 0;
        }

        $assignments = [];
        $parameters = [':id' => $id];

        foreach ($fields as $column => $value) {
            $assignments[] = $this->quote($column) . ' = :' . $column;
            $parameters[':' . $column] = $value;
        }

        return $this->run(
            'UPDATE product_pack_sizes SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    public function deletePackSize(string $productId, string $id): int
    {
        return $this->run(
            'DELETE FROM product_pack_sizes WHERE id = :id AND product_id = :product_id',
            [':id' => $id, ':product_id' => $productId],
        );
    }

    /** Every other pack size on this product stops being the default. */
    public function clearOtherDefaultPackSizes(string $productId, string $exceptId): void
    {
        $this->run(
            'UPDATE product_pack_sizes SET is_default = 0
              WHERE product_id = :product_id AND id != :except',
            [':product_id' => $productId, ':except' => $exceptId],
        );
    }

    /** Delete-and-reinsert on the whole set — see the class doc for why that is safe here. */
    public function replacePackSizes(string $productId): void
    {
        $this->run('DELETE FROM product_pack_sizes WHERE product_id = :product_id', [':product_id' => $productId]);
    }

    /** @return list<string> pack size ids in this product's set, in sort order */
    public function packSizeIds(string $productId): array
    {
        $rows = $this->all(
            'SELECT id FROM product_pack_sizes WHERE product_id = :product_id ORDER BY sort_order',
            [':product_id' => $productId],
        );

        return array_map(static fn (array $r): string => (string) $r['id'], $rows);
    }

    // ─── highlights ──────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function highlights(string $productId): array
    {
        return $this->all(
            'SELECT id, product_id, title, subtitle, icon_name, sort_order
               FROM product_highlights WHERE product_id = :product_id
              ORDER BY sort_order',
            [':product_id' => $productId],
        );
    }

    /** @return array<string,mixed>|null */
    public function findHighlight(string $productId, string $id): ?array
    {
        return $this->one(
            'SELECT id, product_id, title, subtitle, icon_name, sort_order
               FROM product_highlights WHERE id = :id AND product_id = :product_id',
            [':id' => $id, ':product_id' => $productId],
        );
    }

    /** @param array<string,scalar|null> $fields */
    public function createHighlight(string $productId, array $fields): string
    {
        $id = UlidHelper::generate();
        $row = $fields + ['id' => $id, 'product_id' => $productId];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO product_highlights (' . implode(', ', array_map($this->quote(...), $columns)) . ')
             VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')',
            $row,
        );

        return $id;
    }

    /** @param array<string,scalar|null> $fields */
    public function updateHighlight(string $id, array $fields): int
    {
        if ($fields === []) {
            return 0;
        }

        $assignments = [];
        $parameters = [':id' => $id];

        foreach ($fields as $column => $value) {
            $assignments[] = $this->quote($column) . ' = :' . $column;
            $parameters[':' . $column] = $value;
        }

        return $this->run(
            'UPDATE product_highlights SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    public function deleteHighlight(string $productId, string $id): int
    {
        return $this->run(
            'DELETE FROM product_highlights WHERE id = :id AND product_id = :product_id',
            [':id' => $id, ':product_id' => $productId],
        );
    }

    public function replaceHighlights(string $productId): void
    {
        $this->run('DELETE FROM product_highlights WHERE product_id = :product_id', [':product_id' => $productId]);
    }

    // ─── images ──────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function images(string $productId): array
    {
        return $this->all(
            'SELECT pi.id, pi.product_id, pi.media_id, pi.is_primary, pi.sort_order,
                    m.secure_url AS media_url, m.alt_text AS media_alt
               FROM product_images pi
               JOIN media_assets m ON m.id = pi.media_id
              WHERE pi.product_id = :product_id
              ORDER BY pi.sort_order',
            [':product_id' => $productId],
        );
    }

    /** @return array<string,mixed>|null */
    public function findImage(string $productId, string $id): ?array
    {
        return $this->one(
            'SELECT id, product_id, media_id, is_primary, sort_order
               FROM product_images WHERE id = :id AND product_id = :product_id',
            [':id' => $id, ':product_id' => $productId],
        );
    }

    public function mediaAssetExists(string $mediaId): bool
    {
        return $this->scalar('SELECT id FROM media_assets WHERE id = :id', [':id' => $mediaId]) !== null;
    }

    /** @param array<string,scalar|null> $fields */
    public function createImage(string $productId, array $fields): string
    {
        $id = UlidHelper::generate();
        $row = $fields + ['id' => $id, 'product_id' => $productId];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO product_images (' . implode(', ', array_map($this->quote(...), $columns)) . ')
             VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')',
            $row,
        );

        return $id;
    }

    /** @param array<string,scalar|null> $fields */
    public function updateImage(string $id, array $fields): int
    {
        if ($fields === []) {
            return 0;
        }

        $assignments = [];
        $parameters = [':id' => $id];

        foreach ($fields as $column => $value) {
            $assignments[] = $this->quote($column) . ' = :' . $column;
            $parameters[':' . $column] = $value;
        }

        return $this->run(
            'UPDATE product_images SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    public function deleteImage(string $productId, string $id): int
    {
        return $this->run(
            'DELETE FROM product_images WHERE id = :id AND product_id = :product_id',
            [':id' => $id, ':product_id' => $productId],
        );
    }

    public function clearOtherPrimaryImages(string $productId, string $exceptId): void
    {
        $this->run(
            'UPDATE product_images SET is_primary = 0 WHERE product_id = :product_id AND id != :except',
            [':product_id' => $productId, ':except' => $exceptId],
        );
    }

    public function replaceImages(string $productId): void
    {
        $this->run('DELETE FROM product_images WHERE product_id = :product_id', [':product_id' => $productId]);
    }

    // ─── shared ──────────────────────────────────────────────────────────────

    /**
     * @return array{0:string,1:array<string,scalar|null>}
     */
    private function listFilter(?string $search, ?string $status, ?string $categoryId): array
    {
        $where = 'WHERE deleted_at IS NULL';
        $parameters = [];

        if ($search !== null && $search !== '') {
            $where .= ' AND name LIKE :search';
            $parameters[':search'] = '%' . addcslashes($search, '%_\\') . '%';
        }

        if ($status !== null) {
            $where .= ' AND status = :status';
            $parameters[':status'] = $status;
        }

        if ($categoryId !== null) {
            $where .= ' AND category_id = :category_id';
            $parameters[':category_id'] = $categoryId;
        }

        return [$where, $parameters];
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
