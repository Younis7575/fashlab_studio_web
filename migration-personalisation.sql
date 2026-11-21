-- ---------------------------------------------------------------------------
-- Image alt text + product personalisation
--
-- Additive only (three nullable columns). Two ways to apply:
--   1. Admin → Database Update → "Apply update" (recommended, idempotent), or
--   2. phpMyAdmin → Import this file on the live database.
-- Re-running option 2 after option 1 fails with "Duplicate column" — harmless.
-- ---------------------------------------------------------------------------

ALTER TABLE `product_images`
  ADD COLUMN `alt_text` VARCHAR(200) NULL DEFAULT NULL AFTER `image`;

ALTER TABLE `products`
  ADD COLUMN `personalisation_fields` TEXT NULL AFTER `size`;

ALTER TABLE `order_items`
  ADD COLUMN `personalisation` TEXT NULL AFTER `variant_label`;
