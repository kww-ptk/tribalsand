-- Seed: HR directory roster (73 staff). Idempotent — each row guarded by
-- NOT EXISTS on (full_name, unit_label), so re-running never duplicates.
-- Web-runnable via /admin/migrate.php. Depends on add_hr_staff.
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'CAROLINE MAKENA MURUNGI', 'HOUSE SUPERVISOR', 'Housekeeping', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', '', 'active', 10
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='CAROLINE MAKENA MURUNGI' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'ALBERT KITI', 'COOK', 'Kitchen', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'TUE', 'active', 20
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='ALBERT KITI' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'JUDY ADHIAMBO', 'HOUSE KEEPER', 'Housekeeping', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'THU', 'active', 30
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='JUDY ADHIAMBO' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'MAUREEN  KOMBE', 'HOUSE KEEPER', 'Housekeeping', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'TUE', 'active', 40
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='MAUREEN  KOMBE' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'PASCAL JUMA', 'HOUSE KEEPER', 'Housekeeping', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', '', 'active', 50
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='PASCAL JUMA' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'FRANCIS C. MWALUNE', 'WAITER', 'Service', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', '', 'active', 60
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='FRANCIS C. MWALUNE' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'GILBERT KWICHA', '', 'Other', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', '', 'active', 70
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='GILBERT KWICHA' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'MTAWALI NASORO', 'HEAD GARDENER', 'Gardening', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'WED', 'active', 80
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='MTAWALI NASORO' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'BARAKA KAZUNGU CHARO', 'GARDENER', 'Gardening', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'TUE', 'active', 90
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='BARAKA KAZUNGU CHARO' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'MICHAEL KAZUNGU CHARO', 'GARDENER', 'Gardening', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'THU', 'active', 100
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='MICHAEL KAZUNGU CHARO' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'SALIM PESA', 'GARDENER', 'Gardening', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'FRI', 'active', 110
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='SALIM PESA' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'STEPHEN CHARO UNDA', 'POOL ATTENDANT', 'Housekeeping', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'MON', 'active', 120
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='STEPHEN CHARO UNDA' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'KENNEDY NYALE', 'CARPENTER', 'Maintenance', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'SUN', 'active', 130
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='KENNEDY NYALE' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'ISAAC BETT', 'MAINTENANCE', 'Maintenance', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'FRI', 'active', 140
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='ISAAC BETT' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'KELVIN MJIMBA', 'HEAD WATCHMAN', 'Security', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'SAT', 'active', 150
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='KELVIN MJIMBA' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'JONAH MEIBUKO', 'WATCHMAN NIGHT', 'Security', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'MON', 'active', 160
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='JONAH MEIBUKO' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'DANSON KUYAN LEMARON', 'WATCHMAN NIGHT', 'Security', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'WED', 'active', 170
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='DANSON KUYAN LEMARON' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'STEPHEN NGUMBAO GONA', 'WATCHMAN RLVER', 'Security', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'THU', 'active', 180
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='STEPHEN NGUMBAO GONA' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'JOHN KAZEMBERA MLACHA', 'WATCHMAN', 'Security', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', 'WED', 'active', 190
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='JOHN KAZEMBERA MLACHA' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'SANKORI', 'CASUAL', 'Other', (SELECT id FROM venues WHERE slug='maya-kobe'), 'MK', '', 'active', 200
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='SANKORI' AND COALESCE(unit_label,'')='MK');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'JAMES NDORO', 'STORE KEEPER', 'Stores', NULL, 'TRIBAL TABLE', 'SAT', 'active', 210
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='JAMES NDORO' AND COALESCE(unit_label,'')='TRIBAL TABLE');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'PETER SUERO', '', 'Other', NULL, 'TRIBAL TABLE', 'WED', 'active', 220
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='PETER SUERO' AND COALESCE(unit_label,'')='TRIBAL TABLE');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'ALEX MWANTHI NGUMA', 'MANAGER', 'Admin', (SELECT id FROM venues WHERE slug='maya_ilai'), 'MAYA ILAI', '', 'active', 230
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='ALEX MWANTHI NGUMA' AND COALESCE(unit_label,'')='MAYA ILAI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'JENNIFER WILLIE', 'HOUSE KEEPER', 'Housekeeping', (SELECT id FROM venues WHERE slug='maya_ilai'), 'MAYA ILAI', 'SUN', 'active', 240
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='JENNIFER WILLIE' AND COALESCE(unit_label,'')='MAYA ILAI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'LIKAMBA NANA -MI', 'WATCHMAN NIGHT', 'Security', (SELECT id FROM venues WHERE slug='maya_ilai'), 'MAYA ILAI', 'TUE', 'active', 250
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='LIKAMBA NANA -MI' AND COALESCE(unit_label,'')='MAYA ILAI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'NERBERT SHAHA - MI', 'WATCHMAN NIGHT', 'Security', (SELECT id FROM venues WHERE slug='maya_ilai'), 'MAYA ILAI', 'WED', 'active', 260
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='NERBERT SHAHA - MI' AND COALESCE(unit_label,'')='MAYA ILAI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'SATIO OLE METUI - M. ILAI', 'WATCHMAN NIGHT', 'Security', (SELECT id FROM venues WHERE slug='maya_ilai'), 'MAYA ILAI', 'FRI', 'active', 270
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='SATIO OLE METUI - M. ILAI' AND COALESCE(unit_label,'')='MAYA ILAI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'ALOICE CHARO - M. ILAI', 'WATCHMAN DAY', 'Security', (SELECT id FROM venues WHERE slug='maya_ilai'), 'MAYA ILAI', 'THU', 'active', 280
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='ALOICE CHARO - M. ILAI' AND COALESCE(unit_label,'')='MAYA ILAI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'ROBERT MTAWALI KAHAMBI', 'COOK', 'Kitchen', (SELECT id FROM venues WHERE slug='enkare-bofa'), 'ENKARE BOFA - KILIFI', 'WED', 'active', 290
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='ROBERT MTAWALI KAHAMBI' AND COALESCE(unit_label,'')='ENKARE BOFA - KILIFI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'SHIDA KAHINDI MWAJEFA', 'GARDERNER', 'Gardening', (SELECT id FROM venues WHERE slug='enkare-bofa'), 'ENKARE BOFA - KILIFI', 'THU', 'active', 300
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='SHIDA KAHINDI MWAJEFA' AND COALESCE(unit_label,'')='ENKARE BOFA - KILIFI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'LENICE', 'HOUSE KEEPER', 'Housekeeping', (SELECT id FROM venues WHERE slug='enkare-bofa'), 'ENKARE BOFA - KILIFI', 'TUE', 'active', 310
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='LENICE' AND COALESCE(unit_label,'')='ENKARE BOFA - KILIFI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'JOSPHINE', '', 'Other', (SELECT id FROM venues WHERE slug='enkare-bofa'), 'ENKARE BOFA - KILIFI', '', 'active', 320
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='JOSPHINE' AND COALESCE(unit_label,'')='ENKARE BOFA - KILIFI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'SANKORI', 'WATCHMAN NIGHT', 'Security', (SELECT id FROM venues WHERE slug='enkare-bofa'), 'ENKARE BOFA - KILIFI', 'WED', 'active', 330
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='SANKORI' AND COALESCE(unit_label,'')='ENKARE BOFA - KILIFI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'ANTHLEM AGGOI', 'OPERATIONS SUPERV', 'Admin', (SELECT id FROM venues WHERE slug='sandbox'), 'SANDBOX - KILIFI', 'WED', 'active', 340
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='ANTHLEM AGGOI' AND COALESCE(unit_label,'')='SANDBOX - KILIFI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'JOSEPHAT KAHINDI IHA', 'GARDENER', 'Gardening', (SELECT id FROM venues WHERE slug='sandbox'), 'SANDBOX - KILIFI', 'MON', 'active', 350
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='JOSEPHAT KAHINDI IHA' AND COALESCE(unit_label,'')='SANDBOX - KILIFI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'FLORENCE  BENJAMIN', 'HOUSE KEEPER', 'Housekeeping', (SELECT id FROM venues WHERE slug='sandbox'), 'SANDBOX - KILIFI', 'WED', 'active', 360
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='FLORENCE  BENJAMIN' AND COALESCE(unit_label,'')='SANDBOX - KILIFI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'KEREKU OLE NANA', 'WATCHMAN NIGHT', 'Security', (SELECT id FROM venues WHERE slug='sandbox'), 'SANDBOX - KILIFI', 'TUE', 'active', 370
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='KEREKU OLE NANA' AND COALESCE(unit_label,'')='SANDBOX - KILIFI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'KATANA CHARO KITI', 'DOGS CARE', 'Other', (SELECT id FROM venues WHERE slug='sandbox'), 'SANDBOX - KILIFI', 'FRI', 'active', 380
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='KATANA CHARO KITI' AND COALESCE(unit_label,'')='SANDBOX - KILIFI');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'HENRY OUMA OKAKA', 'GARDNER', 'Gardening', (SELECT id FROM venues WHERE slug='my-amani'), 'MY AMANI - VIPINGO', 'TUE', 'active', 390
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='HENRY OUMA OKAKA' AND COALESCE(unit_label,'')='MY AMANI - VIPINGO');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'LILIAN AWINO', 'HOUSE KEEPER', 'Housekeeping', (SELECT id FROM venues WHERE slug='my-amani'), 'MY AMANI - VIPINGO', 'WED', 'active', 400
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='LILIAN AWINO' AND COALESCE(unit_label,'')='MY AMANI - VIPINGO');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'VICTOR MATATA SARUSI', 'CARETAKER', 'Other', (SELECT id FROM venues WHERE slug='my-amani'), 'MY AMANI - VIPINGO', 'THU', 'active', 410
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='VICTOR MATATA SARUSI' AND COALESCE(unit_label,'')='MY AMANI - VIPINGO');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'NDETE KITURET', 'WATCHMAN NIGHT', 'Security', (SELECT id FROM venues WHERE slug='my-amani'), 'MY AMANI - VIPINGO', 'FRI', 'active', 420
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='NDETE KITURET' AND COALESCE(unit_label,'')='MY AMANI - VIPINGO');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'MOSES YAA', 'HOUSE SUPERVISOR', 'Housekeeping', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'THU', 'active', 430
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='MOSES YAA' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'GEORGE CHIPANGA', 'CHEF', 'Kitchen', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'WED', 'active', 440
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='GEORGE CHIPANGA' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'FREDRICK MWANGUE', 'COOK', 'Kitchen', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'TUE', 'active', 450
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='FREDRICK MWANGUE' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'KHAMIS CHENGO', 'WAITER', 'Service', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'FRI', 'active', 460
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='KHAMIS CHENGO' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'FERDINAND WANJE SAFARI', 'WAITER', 'Service', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'TUE', 'active', 470
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='FERDINAND WANJE SAFARI' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'ANTHONY MITINGI', 'WAITER', 'Service', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'THU', 'active', 480
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='ANTHONY MITINGI' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT '(ALICE) MNYAZI JOSHUA', 'HOUSE KEEPER', 'Housekeeping', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'TUE', 'active', 490
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='(ALICE) MNYAZI JOSHUA' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'MARIAM SAID', 'HOUSE KEEPER', 'Housekeeping', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'TUE', 'active', 500
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='MARIAM SAID' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'BLENCH  WANJIKU NGURE', 'HOUSE KEEPER', 'Housekeeping', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'THU', 'active', 510
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='BLENCH  WANJIKU NGURE' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'JUMAA GARAMA', 'S/POOL ATTENDANT', 'Housekeeping', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'FRI', 'active', 520
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='JUMAA GARAMA' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'GIDEON  JEFWA YAA', 'ELECTRICIAN', 'Maintenance', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'WED', 'active', 530
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='GIDEON  JEFWA YAA' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'JOSEPH  MWAVUO', 'PLUMBER', 'Maintenance', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'MON', 'active', 540
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='JOSEPH  MWAVUO' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'MARTIN SHUHULI KARISA', 'GARDENER', 'Gardening', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'FRI', 'active', 550
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='MARTIN SHUHULI KARISA' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'KATANA  CHANGAWA CHEA', 'GARDENER', 'Gardening', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'SUN', 'active', 560
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='KATANA  CHANGAWA CHEA' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'KANZE  KALUME MBITHA', 'GARDENER', 'Gardening', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'THU', 'active', 570
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='KANZE  KALUME MBITHA' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'NICKSON  KHAMISI CHARO', 'GARDENER', 'Gardening', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'WED', 'active', 580
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='NICKSON  KHAMISI CHARO' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'JUMA KOMBE', 'WATCHMAN DAY', 'Security', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'FRI', 'active', 590
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='JUMA KOMBE' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'LEYIAN OLE MATAINE', 'WATCHMAN NIGHT', 'Security', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'WED', 'active', 600
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='LEYIAN OLE MATAINE' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'LEIYAN OLE HAMISI', 'WATCHMAN NIGHT', 'Security', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'TUE', 'active', 610
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='LEIYAN OLE HAMISI' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'JOSEPHAT BAYA', 'WATCHMAN NIGHT', 'Security', (SELECT id FROM venues WHERE slug='zuri'), 'ZURI - WATAMU', 'THU', 'active', 620
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='JOSEPHAT BAYA' AND COALESCE(unit_label,'')='ZURI - WATAMU');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'Rajab Amani', 'GARDENER', 'Gardening', NULL, 'Watamu - Plot  6', 'SUN', 'active', 630
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='Rajab Amani' AND COALESCE(unit_label,'')='Watamu - Plot  6');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'Nickson Ashivira Musonye', 'GARDENER', 'Gardening', NULL, 'Watamu - Plot  22', 'SUN', 'active', 640
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='Nickson Ashivira Musonye' AND COALESCE(unit_label,'')='Watamu - Plot  22');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'Allan Rafiki', 'ACCOUNTATNT', 'Admin', NULL, 'Tribal Sand Center (office)', 'SUN', 'active', 650
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='Allan Rafiki' AND COALESCE(unit_label,'')='Tribal Sand Center (office)');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'Nusra Hassan', 'HR', 'Admin', NULL, 'Tribal Sand Center (office)', 'SUN', 'active', 660
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='Nusra Hassan' AND COALESCE(unit_label,'')='Tribal Sand Center (office)');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'Christine Omondi', 'ASS.HR', 'Admin', NULL, 'Tribal Sand Center (office)', 'SUN', 'active', 670
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='Christine Omondi' AND COALESCE(unit_label,'')='Tribal Sand Center (office)');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'Faiza Lungu Kamu', 'RESERVATIONS', 'Admin', NULL, 'Tribal Sand Center (office)', 'SUN', 'active', 680
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='Faiza Lungu Kamu' AND COALESCE(unit_label,'')='Tribal Sand Center (office)');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'Leticia Atieno', 'RESERVATIONS', 'Admin', NULL, 'Tribal Sand Center (office)', 'SUN', 'active', 690
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='Leticia Atieno' AND COALESCE(unit_label,'')='Tribal Sand Center (office)');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'WILLIAM  kariuki', 'STORE KEEPER', 'Stores', NULL, 'Tribal Sand Center (office)', 'SUN', 'active', 700
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='WILLIAM  kariuki' AND COALESCE(unit_label,'')='Tribal Sand Center (office)');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'BENSON CHONGA KAZUNGU', 'ACCOUNTANT', 'Admin', NULL, 'Tribal Sand Center (office)', 'SUN', 'active', 710
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='BENSON CHONGA KAZUNGU' AND COALESCE(unit_label,'')='Tribal Sand Center (office)');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'Jordan Mwori', 'KITE INSTRUCTOR', 'Service', NULL, 'Tribalsand ltd kite school', 'WED', 'active', 720
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='Jordan Mwori' AND COALESCE(unit_label,'')='Tribalsand ltd kite school');
INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
SELECT 'Ramadhan Zonjee', 'KITE INSTRUCTOR', 'Service', NULL, 'Tribalsand ltd kite school', 'TUE', 'active', 730
WHERE NOT EXISTS (SELECT 1 FROM hr_staff WHERE full_name='Ramadhan Zonjee' AND COALESCE(unit_label,'')='Tribalsand ltd kite school');
