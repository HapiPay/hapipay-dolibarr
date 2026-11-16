-- Copyright (C) HapiPay
-- Licence GPL-3.0-or-later

ALTER TABLE llx_hapipay_page ADD UNIQUE INDEX uk_hapipay_page_reference (reference);
ALTER TABLE llx_hapipay_page ADD INDEX idx_hapipay_page_object (source, fk_object);
