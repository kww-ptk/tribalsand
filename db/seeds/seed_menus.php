<?php
/**
 * Seed the DB-driven restaurant menus for Zuri (full food + drinks) and
 * Maya Kobe (breakfast). Idempotent: re-running wipes each menu by slug
 * (cascade removes its categories + items) and re-inserts from the arrays below.
 *
 * Run:  D:\php84\php.exe db/seeds/seed_menus.php
 * Safe to re-run. Editing here is NOT how the house manager works — this only
 * lays down the starting content; day-to-day edits happen in /admin/menus.php.
 *
 * Item badge tokens: veg vegan spicy nuts gluten gf sig
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/menu.php';

if (!menus_supported()) {
    fwrite(STDERR, "menus tables missing — run db/migrations/add_menus.sql first.\n");
    exit(1);
}

/** Expand a space-separated badge token string into the 7 boolean columns. */
function seed_badges(string $tokens): array {
    $t = preg_split('/\s+/', trim($tokens)) ?: [];
    return [
        'is_veg'       => in_array('veg', $t, true),
        'is_vegan'     => in_array('vegan', $t, true),
        'is_spicy'     => in_array('spicy', $t, true),
        'has_nuts'     => in_array('nuts', $t, true),
        'has_gluten'   => in_array('gluten', $t, true),
        'is_gf'        => in_array('gf', $t, true),
        'is_signature' => in_array('sig', $t, true),
    ];
}

/** [name, price|null, description, badge-tokens] */
function I(string $name, $price = null, string $desc = '', string $badges = ''): array {
    return ['name' => $name, 'price' => $price, 'desc' => $desc, 'badges' => $badges];
}

$menus = [

  // ─────────────────────────── ZURI ───────────────────────────
  [
    'slug' => 'zuri', 'venue_slug' => 'zuri', 'title' => 'Zuri',
    'subtitle' => 'Boutique Hotel · Watamu',
    'tagline'  => 'Mediterranean simplicity, Italian craftsmanship, Indian traditions and the richness of the Kenyan coast.',
    'location' => '📍 Watamu · Kenya\'s North Coast',
    'footer'   => 'Zuri is part of the Tribal Sand collection — coastal properties in Watamu, Kilifi and Vipingo, created with deep respect for Kenya\'s natural beauty, local communities and the environment.',
    'categories' => [
      ['section'=>'food','name'=>'Starter Soups','tag'=>'First Course','icon'=>'🍲','items'=>[
        I('Zuri Garden Velouté',600,'A smooth blend of seasonal vegetables finished with extra virgin olive oil and served with toasted artisan croutons.','veg gluten sig'),
        I('Rustic White Bean Soup',600,'A comforting white bean soup enriched with red spring onions and served with toasted bread.','veg gluten'),
        I('Garden Harvest Soup',600,'A light vegetable broth prepared with seasonal garden vegetables and finished with a delicate touch of chilli.','veg gluten spicy'),
        I('Creamy Zucchini Velouté',600,'Silky zucchini soup served with crispy zucchini, walnuts and fresh stracciatella cheese.','veg nuts'),
        I('Chilled Coastal Tomato Gazpacho',600,'A refreshing cold tomato soup bursting with Mediterranean flavours.','veg'),
      ]],
      ['section'=>'food','name'=>'From Our Garden & Ocean','tag'=>'Starters','icon'=>'🥗','items'=>[
        I('Trio of Samosas',600,'A selection of feta, vegetable and meat samosas served with Zuri\'s signature dipping sauce.','gluten'),
        I('Zuri Signature Bruschetta',600,'Toasted artisan bread layered with black olive pâté, garlic-marinated cherry tomatoes and fresh mozzarella pearls.','veg gluten sig'),
        I('Golden Garden Croquettes',800,'Golden croquettes with eggplant and potato or zucchini and cheese, accompanied by tempura vegetables and Zuri fries.','veg gluten'),
        I('Zuri Crispy Octopus',1000,'Grilled octopus over wild rocket leaves, cherry tomatoes, fresh stracciatella cheese and a vibrant rocket pesto.','sig'),
        I('Ocean Tuna Carpaccio',1200,'Delicately sliced tuna accompanied by sesame seeds, orange segments, teriyaki glaze and fresh rocket.','gluten'),
        I('Oceanic Pink Prawn Tartare',1500,'Fresh pink prawns seasoned with chilli, anchovy, julienned zucchini and crispy sesame.','spicy'),
        I('Indian Ocean Prawn Cocktail',1500,'Succulent prawns served with a refreshing house cocktail dressing.'),
      ]],
      ['section'=>'food','name'=>'Fresh & Vibrant','tag'=>'Salads','icon'=>'🥙','items'=>[
        I('Zuri Exotic Tropical Salad',1200,'A colourful medley of tropical fruits, crisp greens and roasted cashew nuts with a light citrus dressing.','veg nuts sig'),
        I('Mediterranean Brown Rice Salad',1200,'Brown rice tossed with crunchy vegetables, sun-dried tomatoes and feta cheese.','veg'),
        I('Zuri Rice Salad',1200,'A signature rice salad with eggplant cream, potato cubes, caramelised onions and wholemeal bread crumble.','veg gluten'),
        I('Smoky BBQ Chicken Salad',1200,'Tender chicken strips served with crunchy vegetables and a smoky BBQ dressing.'),
        I('Octopus & Potato Salad',1200,'Tender octopus combined with potatoes, herbs and a light citrus dressing.'),
        I('Zuri Prawn Salad',1500,'Fresh garden leaves topped with succulent prawns and seasonal accompaniments.'),
        I('Greek Garden Salad',1200,'Soft lettuce, black olives, feta cheese, onions, tomatoes and toasted bread crumble.','veg gluten'),
        I('Sun-Kissed Chickpea Salad',1200,'A wholesome chickpea salad inspired by Mediterranean flavours.','veg'),
      ]],
      ['section'=>'food','name'=>'Italian Dishes Done Right','tag'=>'Pasta · Gluten-free available on request','icon'=>'🍝','items'=>[
        I('Linguine Alfredo',1200,'Linguine tossed with mushrooms, cream and parmesan cheese.','veg gluten'),
        I('Spaghetti al Pomodoro & Basilico',1200,'A timeless Italian classic prepared with slow-cooked tomato sauce and fresh basil.','veg gluten'),
        I('Rigatoni alla Norma',1200,'Rigatoni served with tomato sauce, eggplant and parmesan cheese.','veg gluten'),
        I('Rigatoni Nerano',1200,'A Southern Italian classic of rigatoni tossed in silky zucchini cream, finished with crispy zucchini, aged parmesan and toasted bread crumble.','veg gluten'),
        I('Spaghetti with Pesto & Peanut Crumble',1200,'Spaghetti tossed in fresh basil pesto and finished with a crunchy peanut crumble.','veg gluten nuts'),
        I('Paccheri Bolognese',1400,'Large Paccheri coated in a slow-cooked beef ragù finished with Parmigiano Reggiano.','gluten'),
        I('Gnocchi Mediterraneo',1400,'Potato gnocchi with fried eggplant, cherry tomatoes and crispy bacon.','gluten'),
        I('Spinach & Ricotta Ravioli',1600,'Delicate homemade ravioli served over a silky red cabbage cream.','veg gluten'),
        I('Indian Ocean Lobster Linguine',2200,'Fresh lobster served with linguine in a delicate seafood sauce.','gluten'),
        I('Maltagliati ai Frutti di Mare',2200,'Fresh pasta ribbons served in a rich shellfish sauce.','gluten sig'),
      ]],
      ['section'=>'food','name'=>'Fresh from the Ocean','tag'=>'Mains · Seafood','icon'=>'🐟','items'=>[
        I('Pepper & Ginger White Fish',1800,'Ocean fish marinated with pepper and ginger, grilled to perfection and served with a curried mayonnaise.'),
        I('Zuri Fish & Chips',1800,'Golden battered fish served with a leek and chilli infused sauce.','gluten sig'),
        I('Braised Octopus Guazzetto',1800,'Tender octopus served over a silky potato velouté with toasted bread.','gluten'),
        I('Seared Tuna Fillet',2200,'Perfectly seared tuna fillet served with seasonal accompaniments.'),
        I('Curried Prawn Tails',2500,'Curried prawns served with crispy basmati rice medallions.','spicy'),
        I('Crispy Calamari & Prawns',2500,'Lightly fried calamari and prawns accompanied by tempura vegetables.','gluten'),
        I('Catalan Lobster',3200,'Fresh lobster prepared with tomatoes, onions, parsley and a lemon dressing.'),
      ]],
      ['section'=>'food','name'=>'For the Meat Lovers','tag'=>'Mains · Meat & Poultry','icon'=>'🥩','items'=>[
        I('Zuri Signature Beef Burger',1500,'A 180g beef burger with tomatoes, red wine onions, scamorza cheese, crisp lettuce and chilli mayonnaise.','gluten sig'),
        I('Coastal Chicken Curry',1800,'Tender chicken cooked in a fragrant curry sauce and served with basmati rice.','spicy'),
        I('Crispy Panko Chicken Cutlet',1800,'Golden panko-crusted chicken served with garlic aioli and crispy zucchini.','gluten'),
        I('Chargrilled Chicken & Zuri Fries',1800,'Simply grilled chicken served with our signature fries.'),
        I('Rosemary Beef Tenderloin',2200,'Sliced beef fillet infused with rosemary and served with buttered potato wedges and lime yoghurt sauce.'),
        I('Beef Fillet with Mushroom Cream',2500,'Premium beef fillet served with a rich mushroom cream and a potato basket.'),
      ]],
      ['section'=>'food','name'=>'Indian Inspired','tag'=>'Mains','icon'=>'🍛','items'=>[
        I('Masala Dosa Trio',1400,'Traditional dosas served with two flavourful fillings inspired by Southern India.','veg gluten spicy'),
        I('Aloo & Egg Curry',1400,'Potato and egg curry accompanied by vegetable paratha.','veg spicy'),
        I('Vegetable Korma',1400,'Seasonal vegetables cooked in a fragrant coconut curry and served with coconut rice.','veg spicy'),
        I('Paneer Butter Masala',1600,'Soft paneer gently simmered in a velvety tomato and butter sauce infused with aromatic spices, served with fragrant jeera rice.','veg spicy'),
        I('Chicken Tikka Masala',1800,'Tender chicken cooked in a creamy tomato and curry sauce.','spicy'),
      ]],
      ['section'=>'food','name'=>'Tasty Sides','tag'=>'All 500 Kes','icon'=>'🍟','items'=>[
        I('Zuri Fries',500),
        I('Mixed Garden Salad',500),
        I('Grilled Vegetables',500),
        I('Buttered Potato Wedges',500),
        I('Sautéed Spinach',500),
      ]],
      ['section'=>'food','name'=>'Sweet Endings','tag'=>'Desserts','icon'=>'🍮','items'=>[
        I('Tropical Fruit Selection',500,'A refreshing selection of freshly cut seasonal fruits.','vegan'),
        I('Zurimisu\'',800,'Zuri\'s signature interpretation of the classic Italian tiramisu.','sig'),
        I('Chocolate Brownie',800,'Warm chocolate brownie served with walnut ice cream and melted chocolate.','nuts'),
        I('Mini Cheesecake',800,'Creamy cheesecake served in an elegant individual portion.'),
        I('Apple Tartlet',800,'Classic apple tartlet with a delicate pastry crust.','gluten'),
        I('Exotic Tart',800,'A tropical fruit tart inspired by the flavours of the coast.','gluten'),
        I('Crêpe with Fresh Fruits & Chocolate Sauce',800,'Freshly prepared crêpe topped with seasonal fruits and chocolate sauce.','gluten'),
      ]],

      ['section'=>'drinks','name'=>'Signature Cocktails','tag'=>'All 1,200 Kes','icon'=>'🍸','items'=>[
        I('Dawa',1200,'Kenya\'s iconic cocktail with vodka, lime, honey and crushed ice.'),
        I('Mojito',1200,'White rum, fresh mint, lime and soda water.'),
        I('Piña Colada',1200,'White rum, coconut cream and pineapple juice.'),
        I('Gin & Tonic',1200,'A timeless classic served over ice with fresh lime.'),
        I('Margarita',1200,'Tequila, lime juice and orange liqueur with a salted rim.'),
        I('Strawberry Daiquiri',1200,'Fresh strawberries, white rum and lime juice.'),
        I('Aperol Spritz',1200,'Aperol, prosecco and soda water with orange.'),
        I('Campari Spritz',1200,'Campari, prosecco and soda water with orange.'),
      ]],
      ['section'=>'drinks','name'=>'Refreshing Mocktails','tag'=>'All 800 Kes · Alcohol-Free','icon'=>'🥤','items'=>[
        I('Virgin Mojito',800,'Mint, lime and soda water.'),
        I('Tropical Sunrise',800,'Orange juice, grenadine and soda water.'),
        I('Coconut Cooler',800,'Coconut water, lime and honey.'),
        I('Mango Ginger Fizz',800,'Mango purée, fresh ginger and soda water.'),
        I('Fruit Punch',800,'A tropical blend of fresh fruit juices.'),
      ]],
      ['section'=>'drinks','name'=>'Fresh Juices','tag'=>'All 450 Kes','icon'=>'🍊','items'=>[
        I('Pineapple Juice',450),
        I('Passion Fruit Juice',450),
        I('Mango Juice',450),
        I('Orange Juice',450),
      ]],
      ['section'=>'drinks','name'=>'Beer & Cider','tag'=>'All 700 Kes','icon'=>'🍺','items'=>[
        I('Craft Kenyan Beer',700),
        I('Corona Extra',700),
        I('Desperados',700),
        I('Savanna Dry Cider',700),
      ]],
      ['section'=>'drinks','name'=>'Soft Drinks','tag'=>'Water & Sodas','icon'=>'💧','items'=>[
        I('Sparkling Water 330ml',300),
        I('Sparkling Water 700ml',400),
        I('Soda',250,'Coca-Cola, Coca-Cola Light, Fanta, Sprite, Lemon Krest, Stoney, Tonic Water, Soda Water.'),
      ]],
      ['section'=>'drinks','name'=>'White Wines','tag'=>'Bottle','icon'=>'🥂','items'=>[
        I('Bruce Jack Chenin Blanc (South Africa)',3600,'Fresh, fruity, easy with notes of peaches.'),
        I('Hesketh Sauvignon Blanc (Australia)',4000,'Citrusy and aromatic with tropical fruit notes.'),
        I('Clear Water Cove Sauvignon Blanc (New Zealand)',4500,'Bright, crisp, refreshingly dry.'),
        I('Bruce Jack Reserve Chardonnay (South Africa)',4800,'Smooth, elegant with subtle oak notes.'),
        I('Fantinel Borgo Tesis Pinot Grigio (Italy)',5500,'Light, crisp and mineral-driven.'),
      ]],
      ['section'=>'drinks','name'=>'Red Wines','tag'=>'Bottle','icon'=>'🍷','items'=>[
        I('Bruce Jack Pinotage Malbec (South Africa)',3600,'Rich dark berries with hints of chocolate.'),
        I('Santa Cristina Le Maestrelle (Italy)',5800,'Elegant, balanced with notes of cherry and soft spice.'),
      ]],
      ['section'=>'drinks','name'=>'Rosé Wines','tag'=>'Bottle','icon'=>'🌸','items'=>[
        I('Bruce Jack Sauvignon Blush (South Africa)',3600,'Light, fresh and fruity.'),
        I('Côte des Roses Rosé (France)',5800,'Elegant and aromatic with delicate berry notes.'),
      ]],
      ['section'=>'drinks','name'=>'Sparkling Wines','tag'=>'Bottle','icon'=>'✨','items'=>[
        I('Fantinel Spumante Cuvée Prestige (Italy)',4000,'Fresh and delicate with floral aromas.'),
        I('The Independent Prosecco Brut (Italy)',6200,'Crisp and lively with fine bubbles.'),
      ]],
    ],
  ],

  // ───────────────────────── MAYA KOBE ─────────────────────────
  [
    'slug' => 'maya-kobe-breakfast', 'venue_slug' => 'maya-kobe', 'title' => 'Maya Kobe',
    'subtitle' => 'Breakfast · Tribal Sand · Kilifi',
    'tagline'  => 'Crafted each morning from fresh, seasonal ingredients — a gentle start to the day, served with the warmth of the coast.',
    'location' => '📍 Bofa Beach · Kilifi · Kenya\'s North Coast',
    'footer'   => 'Maya Kobe is part of the Tribal Sand collection — coastal properties in Watamu, Kilifi and Vipingo. Kindly inform your host of any allergies or dietary requirements before ordering.',
    'categories' => [
      ['section'=>'food','name'=>'Included in Your Breakfast','tag'=>'Complimentary','icon'=>'✓','items'=>[
        I('Eggs, Prepared to Order','','Scrambled · Hard-Boiled · Fried · Spanish Omelette · Plain Omelette'),
        I('From the Griddle','','Pancake · Crepe'),
        I('Sides','','Bacon · Beef / Pork / Chicken Sausage'),
        I('Fruits & Grains','','Granola with Yogurt · Fresh Season Fruits'),
        I('Beverages','','Milk · French Press Coffee · Tea · Fresh Juice'),
      ]],
      ['section'=>'food','name'=>'Signature Plates','tag'=>'Charged Separately','icon'=>'★','items'=>[
        I('Poached Egg & Smashed Avocado Toast',600,'Toasted sourdough, smashed avocado, delicately poached egg, toasted mixed seeds and a drizzle of chilli oil and extra virgin olive oil.','veg gluten spicy sig'),
        I('Shakshuka',480,'Slow-simmered tomato, red pepper, onion and garlic, warmed with cumin and sweet paprika, finished with fresh coriander.','veg spicy'),
        I('Lemon Ricotta Pancakes',600,'Fluffy ricotta pancakes scented with lemon zest, layered with seasonal fresh fruit and honey butter.','veg gluten'),
        I('Coconut Chia & Mango Bowl',800,'Chia seeds gently soaked in coconut milk, layered with mango, passion fruit, toasted cashew nuts and almond flakes.','vegan nuts'),
      ]],
      ['section'=>'food','name'=>'Additions','tag'=>'Sides','icon'=>'➕','items'=>[
        I('Sautéed Mushrooms',300),
        I('Hash Browns',600),
        I('Bacon',300),
        I('Seared Tuna',800),
      ]],
      ['section'=>'drinks','name'=>'Coffee','tag'=>'Freshly Brewed','icon'=>'☕','items'=>[
        I('Single Espresso',250),
        I('Double Espresso',350),
        I('Cappuccino',420),
        I('Mocha',550),
        I('Americano',200),
      ]],
      ['section'=>'drinks','name'=>'Tea','tag'=>'A Coastal Ritual','icon'=>'🍵','items'=>[
        I('English Breakfast Tea',200),
        I('Kerico Gold Kenya Tea',200),
        I('Home Made Masala Tea',300),
      ]],
    ],
  ],
];

// ── Insert (idempotent per menu slug) ────────────────────────────────
$venueId = fn(string $slug) => db_query('SELECT id FROM venues WHERE slug = :s', [':s'=>$slug])->fetchColumn() ?: null;

db()->beginTransaction();
try {
    $sortMenu = 0;
    foreach ($menus as $m) {
        db_query('DELETE FROM menus WHERE slug = :s', [':s'=>$m['slug']]);
        db_query(
            "INSERT INTO menus (venue_id,slug,title,subtitle,tagline,location_label,footer_note,sort_order)
             VALUES (:v,:s,:t,:sub,:tag,:loc,:foot,:so)",
            [
                ':v'=>$venueId($m['venue_slug']), ':s'=>$m['slug'], ':t'=>$m['title'],
                ':sub'=>$m['subtitle'], ':tag'=>$m['tagline'], ':loc'=>$m['location'],
                ':foot'=>$m['footer'], ':so'=>$sortMenu++,
            ]
        );
        $menuId = (int) db()->lastInsertId();

        $sortCat = 0;
        foreach ($m['categories'] as $c) {
            db_query(
                "INSERT INTO menu_categories (menu_id,section,name,tag,icon,sort_order)
                 VALUES (:m,:sec,:n,:tag,:ic,:so)",
                [':m'=>$menuId, ':sec'=>$c['section'], ':n'=>$c['name'], ':tag'=>$c['tag'] ?? '', ':ic'=>$c['icon'] ?? '', ':so'=>$sortCat++]
            );
            $catId = (int) db()->lastInsertId();

            $sortItem = 0;
            foreach ($c['items'] as $it) {
                $b = seed_badges($it['badges']);
                db_query(
                    "INSERT INTO menu_items
                       (category_id,name,description,price,is_veg,is_vegan,is_spicy,has_nuts,has_gluten,is_gf,is_signature,sort_order)
                     VALUES (:c,:n,:d,:p,:veg,:vgn,:sp,:nut,:glu,:gf,:sig,:so)",
                    [
                        ':c'=>$catId, ':n'=>$it['name'], ':d'=>$it['desc'],
                        ':p'=>($it['price']===''||$it['price']===null)?null:$it['price'],
                        ':veg'=>$b['is_veg']?'t':'f', ':vgn'=>$b['is_vegan']?'t':'f', ':sp'=>$b['is_spicy']?'t':'f',
                        ':nut'=>$b['has_nuts']?'t':'f', ':glu'=>$b['has_gluten']?'t':'f', ':gf'=>$b['is_gf']?'t':'f',
                        ':sig'=>$b['is_signature']?'t':'f', ':so'=>$sortItem++,
                    ]
                );
            }
        }
        echo "Seeded menu '{$m['slug']}' — " . count($m['categories']) . " categories.\n";
    }
    db()->commit();
    echo "Done.\n";
} catch (Throwable $e) {
    db()->rollBack();
    fwrite(STDERR, "SEED FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
