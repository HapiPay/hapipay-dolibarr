-- Copyright (C) Hapidit Group
-- Licence GPL-3.0-or-later

ALTER TABLE llx_hapiditpay_page ADD UNIQUE INDEX uk_hapiditpay_page_reference (reference);
ALTER TABLE llx_hapiditpay_page ADD INDEX idx_hapiditpay_page_object (source, fk_object);
