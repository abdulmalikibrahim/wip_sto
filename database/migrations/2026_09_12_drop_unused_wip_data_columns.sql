-- Drop the four Master WIP columns that were never used by any screen or
-- calculation: Color Code, Color Desc, Last Scan and Scan Date.
--
-- The WIP list shows a derived SEQUENCE column instead (a row's position in
-- the cached list, counted from the oldest row up) — that is the number the
-- WIP Calc cutoff counts against, so it is computed from the stored row
-- order rather than kept in a column of its own.
--
-- Uploads still accept files that carry the old 9-column layout; those four
-- cells are simply not read (see Wip_data_model::detect_shop_column()).

ALTER TABLE `wip_data`
  DROP COLUMN `colorcode`,
  DROP COLUMN `colorname`,
  DROP COLUMN `wipname`,
  DROP COLUMN `scandate`;
