<?php
declare(strict_types=1);

final class SqlQueryCatalog
{
    public static function all(): array
    {
        $catalog = [];
        $add = static function (string $id, string $group, string $source, string $description, string $sql, array $params, array $tables, array $indexes) use (&$catalog): void {
            if (isset($catalog[$id])) {
                throw new LogicException("Duplicate query ID: $id");
            }
            $catalog[$id] = [
                'group' => $group, 'source' => $source, 'description' => $description,
                'sql' => $sql, 'params' => $params,
                'expected_tables' => $tables, 'expected_indexes' => $indexes,
            ];
        };
        $columns = 'SELECT p.id, p.category_id, p.subcategory_id, p.name, p.price, p.stock, p.image, c.name AS category_name, c.canonical_key AS category, sc.canonical_key AS subcategory, sc.display_name AS subcategory_name';
        $from = ' FROM products p LEFT JOIN categories c ON p.category_id = c.id LEFT JOIN product_subcategories sc ON p.subcategory_id = sc.id';
        $tables = ['products', 'categories', 'product_subcategories'];
        $add('product_filter', 'product/catalog', 'ProductRepository::listRows', 'Category and price range page',
            $columns . $from . ' WHERE p.category_id = ? AND p.price >= ? AND p.price <= ? ORDER BY p.price ASC LIMIT 30 OFFSET 0',
            [1, 100000, 130000], $tables, ['idx_category_price', 'idx_category_price_stock']);
        $add('product_price', 'product/catalog', 'ProductRepository::listRows', 'Price-only range page',
            $columns . $from . ' WHERE p.price >= ? AND p.price <= ? ORDER BY p.price ASC LIMIT 30 OFFSET 0',
            [100000, 100500], $tables, ['idx_price']);
        $add('product_count', 'joins/aggregates', 'ProductRepository::listCount', 'Count matching category and price',
            'SELECT COUNT(*)' . $from . ' WHERE p.category_id = ? AND p.price >= ? AND p.price <= ?',
            [1, 100000, 130000], $tables, ['idx_category_price', 'idx_category_price_stock']);
        $add('product_color', 'product/catalog', 'ProductRepository::listRows', 'Filter active variants by color',
            $columns . $from . ' WHERE p.category_id = ? AND EXISTS (SELECT 1 FROM product_variants cv JOIN colors cc ON cc.id = cv.color_id WHERE cv.product_id = p.id AND cv.is_active = 1 AND cc.canonical_key = ?) ORDER BY p.id DESC LIMIT 30 OFFSET 0',
            [1, 'black'], [...$tables, 'product_variants', 'colors'], ['idx_product_variant_lookup']);
        $add('product_stock', 'product/catalog', 'ProductRepository::listRows', 'Category and in-stock predicate',
            $columns . $from . ' WHERE p.category_id = ? AND p.price >= ? AND p.price <= ? AND (p.stock > 0 OR EXISTS (SELECT 1 FROM product_variants av WHERE av.product_id = p.id AND av.is_active = 1 AND COALESCE(av.stock, p.stock) > 0)) ORDER BY p.price ASC LIMIT 30 OFFSET 0',
            [1, 100000, 130000], [...$tables, 'product_variants'], ['idx_category_price_stock', 'idx_product_variant_lookup']);
        $add('variant_availability', 'variants', 'VariantRepository::activeByProductIds', 'Active variants for three products',
            'SELECT v.id, v.product_id, v.variant_key, v.sku, v.color_id, v.size, v.price, v.stock, v.is_active, c.canonical_key, c.display_name, c.external_code FROM product_variants v LEFT JOIN colors c ON c.id = v.color_id WHERE v.product_id IN (?, ?, ?) AND v.is_active = 1 ORDER BY v.product_id, v.id',
            [1, 2, 3], ['product_variants', 'colors'], ['idx_product_variant_lookup', 'uq_product_variant_key']);
        $add('variant_key', 'variants', 'VariantRepository::exists', 'Exact product and variant key',
            'SELECT 1 FROM product_variants WHERE product_id = ? AND variant_key = ?',
            [1, 'black|M'], ['product_variants'], ['uq_product_variant_key']);
        $add('variant_legacy', 'variants', 'VariantRepository::legacyVariants', 'Legacy variant prefix',
            'SELECT id, variant_key, size FROM product_variants WHERE product_id = ? AND variant_key LIKE ?',
            [1, 'legacy|%'], ['product_variants'], ['uq_product_variant_key']);
        $add('order_list', 'orders/cart', 'OrderRepository::listForUser', 'Orders filtered by customer and status',
            'SELECT id, total_price, status, created_at FROM orders WHERE user_id = ? AND status = ? ORDER BY created_at DESC',
            [1, 'Đã hoàn thành'], ['orders'], ['user_id']);
        $add('order_lookup', 'orders/cart', 'OrderRepository::findForUser', 'Exact owned order',
            'SELECT id, total_price, status, created_at FROM orders WHERE id = ? AND user_id = ?',
            [1, 1], ['orders'], ['PRIMARY']);
        $add('order_stats', 'joins/aggregates', 'OrderRepository::completedStats', 'Completed order count and spending',
            "SELECT SUM(total_price) AS total_spent, COUNT(*) AS completed_count FROM orders WHERE user_id = ? AND status = 'Đã hoàn thành'",
            [1], ['orders'], ['user_id']);
        $add('order_items', 'joins/aggregates', 'OrderRepository::itemsForOrder', 'Order items and product details',
            'SELECT oi.id, oi.product_id, oi.quantity, oi.price, p.name, p.image FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?',
            [1], ['order_items', 'products'], ['order_id', 'PRIMARY']);
        $add('cart_lookup', 'orders/cart', 'CartRepository::findLine', 'Customer and product cart lookup',
            'SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?',
            [1, 1], ['cart'], ['user_id', 'product_id']);
        $add('cart_items', 'joins/aggregates', 'CartRepository::itemsWithProduct', 'Cart details with subtotal',
            'SELECT c.id AS cart_id, c.product_id, p.name, p.price, p.image, c.quantity, c.size, (p.price * c.quantity) AS subtotal FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ? ORDER BY c.id',
            [1], ['cart', 'products'], ['user_id', 'PRIMARY']);
        $add('chat_history', 'chat/session', 'ChatConversationRepository::messagesForSession', 'Session transcript',
            'SELECT id, role, message, metadata, created_at FROM chat_messages WHERE session_id = ? ORDER BY id ASC',
            [1], ['chat_messages'], ['idx_session_id_created', 'session_id']);
        $add('chat_latest_session', 'chat/session', 'ChatSessionRepository::latestActiveForUser', 'Latest active session',
            "SELECT id, session_token FROM chat_sessions WHERE user_id = ? AND status = 'active' ORDER BY updated_at DESC LIMIT 1",
            [1], ['chat_sessions'], ['idx_user_updated']);
        $add('chat_memory', 'chat/session', 'ChatMemoryRepository::sessionMemory', 'Memory by session key',
            'SELECT summary, slots FROM chat_session_memory WHERE session_id = ?',
            [1], ['chat_session_memory'], ['PRIMARY']);
        $add('support_ticket_status', 'support/FAQ', 'SupportTicketRepository::listForAdmin', 'Admin status page',
            'SELECT t.id, t.user_id, t.order_id, t.type, t.summary, t.status, t.admin_note, t.chat_session_id, t.created_at, t.updated_at, u.username FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.status IN (?) ORDER BY t.created_at DESC, t.id DESC LIMIT 30 OFFSET 0',
            ['moi'], ['support_tickets', 'users'], ['idx_support_tickets_status_created']);
        $add('support_user_count', 'support/FAQ', 'SupportTicketRepository::countOpenForUser', 'Open ticket count for customer',
            'SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND status IN (?, ?)',
            [1, 'moi', 'dang_xu_ly'], ['support_tickets'], ['idx_support_tickets_user_status']);
        $add('faq_category', 'support/FAQ', 'FaqRepository::search', 'Category and FAQ text search',
            'SELECT * FROM faqs WHERE 1=1 AND category = ? AND (question LIKE ? OR answer LIKE ?) ORDER BY priority, id',
            ['shipping', '%shipping%', '%shipping%'], ['faqs'], ['idx_category_priority']);
        return $catalog;
    }
}
