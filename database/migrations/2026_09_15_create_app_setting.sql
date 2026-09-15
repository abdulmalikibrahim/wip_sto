-- App-wide settings, one key/value row each (see Setting_model). First key:
-- `sto_date` — the STO activity date (Y-m-d). Once today (Asia/Jakarta) is
-- past it, "Get Data WIP" on Master WIP KAP 1 / KAP 2 is locked, both the
-- button and the server endpoint, so the WIP snapshot used for the stock
-- opname can't be overwritten by a later pull. No row / NULL = no lock.

CREATE TABLE IF NOT EXISTS `app_setting` (
  `skey` varchar(60) NOT NULL,
  `svalue` text,
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
