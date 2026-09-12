-- Part List: remove duplicate rows and stop them coming back.
--
-- Uploading in "expand" (append) mode inserted every row unconditionally, so
-- re-uploading a file — or uploading the KAP1 and KAP2 files that overlap —
-- duplicated rows that were identical in every field. That is not just table
-- clutter: WIP Calc on the Part List basis sums one line per matching row, so
-- a part duplicated twice counted its usage twice (verified: part 82285-BZ020
-- returned 52 instead of 26).
--
-- The natural key is (model, suffix, part_number, shop_code). shop_code has to
-- be part of it: the same part+model+suffix legitimately appears in different
-- shops with different quantities (e.g. 9004A-35017 on D52B/7H is qty 1 in
-- ASSY3 and qty 2 in TOSO3). Checked against the live data — under this key no
-- duplicate group disagreed on any other field, so dropping the extra rows
-- loses nothing.

-- 1. Keep the earliest row of each duplicate group.
DELETE p FROM `part_list` p
JOIN (
    SELECT MIN(`id`) AS keep_id, `model`, `suffix`, `part_number`, `shop_code`
    FROM `part_list`
    GROUP BY `model`, `suffix`, `part_number`, `shop_code`
    HAVING COUNT(*) > 1
) k
  ON  k.`model`       <=> p.`model`
  AND k.`suffix`      <=> p.`suffix`
  AND k.`part_number` <=> p.`part_number`
  AND k.`shop_code`   <=> p.`shop_code`
WHERE p.`id` > k.keep_id;

-- 2. Let the database enforce it from now on. Uploads use INSERT ... ON
--    DUPLICATE KEY UPDATE against this key (Part_list_model::insert_rows()),
--    so a re-upload refreshes the existing row instead of adding another.
ALTER TABLE `part_list`
  ADD UNIQUE KEY `uniq_part_list_row` (`model`, `suffix`, `part_number`, `shop_code`);
