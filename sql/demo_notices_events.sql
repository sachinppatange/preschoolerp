-- Demo parent notices + school news/events.
-- Safe to run more than once: previous [Demo] rows are removed first.
-- phpMyAdmin: select the preschool database, then Import this file.

DELETE FROM notices WHERE title LIKE '[Demo]%';
DELETE FROM news_events WHERE title LIKE '[Demo]%';

INSERT INTO notices (school_id, title, message, audience, published_at, expires_at, created_at) VALUES
(1, '[Demo] Holiday on Friday',
 'School will remain closed this Friday for a public holiday.\nPlease send a water bottle and a light snack on Thursday — we have a small classroom party.',
 'parents', NOW(), DATE_ADD(NOW(), INTERVAL 21 DAY), NOW()),
(1, '[Demo] Please label bags and bottles',
 'Many water bottles look the same. Write your child''s name on the bag, bottle and tiffin box.\nThis helps teachers send the right things home.',
 'parents', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 45 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, '[Demo] Rainy-day pickup',
 'If it rains heavily at pickup time, please wait in the lobby. Teachers will bring children out class-wise so the verandah stays safe and dry.',
 'parents', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 14 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1, '[Demo] Fees reminder — this month',
 'Kindly clear this month''s fees by the 10th. You can pay at the reception desk (cash / UPI).\nFor a receipt, ask at the desk after payment.',
 'parents', NOW(), DATE_ADD(NOW(), INTERVAL 10 DAY), NOW());

INSERT INTO news_events (type, title, slug, excerpt, content, start_date, end_date, location, is_published, created_at, updated_at) VALUES
('event', '[Demo] Annual Day',
 'demo-annual-day',
 'Songs, dance and a short play. Children come in costume. Parents are welcome from 9:30 am.',
 'Annual Day is in the school hall.\nDrop children by 8:45 am. Programme starts at 9:30 am.\nPlease sit in the parent chairs at the back so little ones can see the stage.',
 DATE_ADD(CURDATE(), INTERVAL 12 DAY), DATE_ADD(CURDATE(), INTERVAL 12 DAY), 'School hall', 1, NOW(), NOW()),
('event', '[Demo] Sports Day',
 'demo-sports-day',
 'Races and fun games for Playgroup to UKG. White house T-shirt if you have one.',
 'Sports Day on the ground behind the building.\nBring a cap, water bottle and a spare pair of shoes.\nFinish around 11:30 am. Pick up from the usual gate.',
 DATE_ADD(CURDATE(), INTERVAL 26 DAY), DATE_ADD(CURDATE(), INTERVAL 26 DAY), 'Playground', 1, NOW(), NOW()),
('event', '[Demo] Colour day — Yellow',
 'demo-colour-day-yellow',
 'Wear something yellow. We will make a yellow collage in class.',
 'Yellow Day. Send a yellow fruit or snack if you like (banana, mango, corn).\nNo jewellery please.',
 DATE_ADD(CURDATE(), INTERVAL 5 DAY), NULL, 'Own classroom', 1, NOW(), NOW()),
('event', '[Demo] Janmashtami celebration',
 'demo-janmashtami',
 'Dahi handi in the courtyard. Dress: ethnic / Krishna-Radha optional.',
 'A short celebration after circle time. Parents may join for 20 minutes at 10:00 am.',
 DATE_SUB(CURDATE(), INTERVAL 18 DAY), DATE_SUB(CURDATE(), INTERVAL 18 DAY), 'Courtyard', 1, DATE_SUB(NOW(), INTERVAL 20 DAY), NOW()),
('news', '[Demo] New climbing frame in the garden',
 'demo-climbing-frame',
 'A small climbing frame is ready in the garden. Teachers stay with the children during outdoor play.',
 'Outdoor play is 20 minutes each morning, weather permitting.\nPlease send sports shoes on outdoor days.',
 CURDATE(), NULL, 'Garden', 1, NOW(), NOW()),
('news', '[Demo] Parent–teacher meeting dates',
 'demo-ptm-dates',
 'PTM this month: Saturday, 9:00–12:00. Book a 10-minute slot at reception.',
 'One parent per child in the classroom. Siblings can wait in the lobby with a helper.\nIf you cannot come, send a WhatsApp message to the class teacher.',
 DATE_ADD(CURDATE(), INTERVAL 8 DAY), NULL, 'Classrooms', 1, NOW(), NOW());
