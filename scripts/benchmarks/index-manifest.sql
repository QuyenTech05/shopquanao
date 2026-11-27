-- Preserve all primary, unique and FK-supporting indexes.
-- idx_support_tickets_user_status supports the user FK and remains in both variants.
ALTER TABLE products DROP INDEX idx_category_price;
ALTER TABLE products DROP INDEX idx_price;
ALTER TABLE products DROP INDEX idx_category_price_stock;
ALTER TABLE product_variants DROP INDEX idx_product_variant_lookup;
ALTER TABLE chat_messages DROP INDEX idx_session_id_created;
ALTER TABLE chat_sessions DROP INDEX idx_user_updated;
ALTER TABLE faqs DROP INDEX idx_category_priority;
ALTER TABLE support_tickets DROP INDEX idx_support_tickets_status_created;
