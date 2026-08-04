<?php

declare(strict_types=1);

/*
 * Deterministic classification of Amazon products into Dominican customs tariff
 * codes. Rules are evaluated IN ORDER and the first match wins, so specific rules
 * come before generic ones ("phone case" before "phone").
 *
 * Patterns are case-insensitive regex fragments applied with word boundaries against
 * the product title and its categories. The matcher appends an optional plural
 * suffix, so singular patterns also catch "Headphones", "Shoes", "Watches".
 *
 * Within a chapter the duty is usually identical (all of 61/62 pays 20%), so picking
 * between sibling subheadings does not move the amount: what matters is landing on
 * the right chapter. The cases where money DOES change are commented.
 */
return [
    // Computing: heading 84.71 comes in duty-free, against the 20% the rest of
    // consumer electronics pays. Mistaking a laptop for a TV triples the tax.
    ['pattern' => 'laptop|macbook|chromebook|notebook computer|ultrabook', 'code' => '8471.30.00'],
    ['pattern' => 'ipad|tablet', 'code' => '8471.30.00'],
    ['pattern' => 'keyboard|teclado', 'code' => '8471.60.10'],
    ['pattern' => 'mouse|raton|mousepad', 'code' => '8471.60.20'],
    ['pattern' => 'stylus|lapiz optico', 'code' => '8471.60.30'],
    ['pattern' => 'hard drive|hard disk|ssd|nvme|disco duro', 'code' => '8471.70.10'],
    ['pattern' => 'desktop computer|computadora de escritorio|all in one pc', 'code' => '8471.60.90'],

    // Networking gear and phone accessories also travel duty-free.
    ['pattern' => 'router|modem|access point', 'code' => '8517.62.20'],
    ['pattern' => 'phone case|phone charger|phone holder|screen protector|funda de telefono|cargador de telefono', 'code' => '8517.62.91'],
    ['pattern' => 'antenna|antena', 'code' => '8517.71.00'],

    ['pattern' => 'smartphone|iphone|galaxy phone|cell phone|cellphone|mobile phone|celular', 'code' => '8517.13.00'],

    ['pattern' => 'headphone|earphone|earbud|airpod|headset|auricular|audifono', 'code' => '8518.30.00'],
    ['pattern' => 'soundbar|speaker|bocina|altavoz|parlante', 'code' => '8518.22.00'],
    ['pattern' => 'microphone|microfono', 'code' => '8518.29.00'],

    // Security cameras: duty-free and ITBIS-exempt under Ley 171-12. A regular camera
    // pays 20% plus ITBIS, so missing this distinction is expensive.
    ['pattern' => 'security camera|surveillance camera|cctv|doorbell camera|camara de seguridad', 'code' => '8525.81.12'],
    ['pattern' => 'webcam', 'code' => '8525.89.12'],
    ['pattern' => 'camera|camcorder|gopro|camara fotografica', 'code' => '8525.89.19'],

    // Computer monitors come in duty-free; televisions pay 20%.
    ['pattern' => 'computer monitor|gaming monitor|monitor', 'code' => '8528.52.00'],
    ['pattern' => 'smart tv|television|televisor|\btv\b', 'code' => '8528.72.00'],
    ['pattern' => 'projector|proyector', 'code' => '8528.59.90'],

    ['pattern' => 'usb flash|flash drive|pendrive|thumb drive|memoria usb', 'code' => '8523.51.10'],
    ['pattern' => 'memory card|micro ?sd|sd card|tarjeta de memoria', 'code' => '8523.51.90'],
    ['pattern' => 'power bank|lithium battery|rechargeable battery|bateria recargable', 'code' => '8507.60.00'],
    ['pattern' => 'game console|playstation|xbox|nintendo switch|videoconsola', 'code' => '9504.50.00'],

    ['pattern' => 'coffee maker|espresso machine|kettle|cafetera|hervidor', 'code' => '8516.71.00'],
    ['pattern' => 'toaster|tostadora', 'code' => '8516.72.00'],
    ['pattern' => 'air fryer|freidora', 'code' => '8516.79.11'],
    ['pattern' => 'blender|licuadora|food processor|procesador de alimentos', 'code' => '8509.40.10'],
    ['pattern' => 'microwave|microonda', 'code' => '8516.50.00'],
    ['pattern' => 'pressure cooker|slow cooker|rice cooker|instant pot|oven|olla de presion|horno', 'code' => '8516.60.10'],
    ['pattern' => 'grill|griddle|parrilla|asador', 'code' => '8516.60.30'],
    ['pattern' => 'vacuum cleaner|aspiradora', 'code' => '8508.11.00'],
    ['pattern' => 'hair clipper|hair trimmer|cortadora de pelo', 'code' => '8510.20.00'],
    ['pattern' => 'fan|ventilador|abanico', 'code' => '8414.51.10'],

    // Power tools sit at 0%, unlike the 20% most home appliances pay.
    ['pattern' => 'drill|taladro', 'code' => '8467.21.00'],
    ['pattern' => 'power saw|circular saw|sierra electrica', 'code' => '8467.22.00'],

    ['pattern' => 'smartwatch|smart watch|apple watch|fitness tracker|smartband', 'code' => '9102.12.00'],
    ['pattern' => 'watch|reloj', 'code' => '9102.19.00'],

    ['pattern' => 'sunglasses|gafas de sol|lentes de sol', 'code' => '9004.10.00'],
    ['pattern' => 'reading glasses|eyeglasses|gafas|espejuelos', 'code' => '9004.90.30'],

    ['pattern' => 'backpack|mochila', 'code' => '4202.92.10'],
    ['pattern' => 'suitcase|luggage|maleta|valija', 'code' => '4202.12.30'],
    ['pattern' => 'handbag|purse|tote bag|bolso|cartera', 'code' => '4202.22.00'],
    ['pattern' => 'wallet|billetera', 'code' => '4202.29.00'],

    ['pattern' => 't-?shirt|tshirt|tank top|polo shirt|camiseta', 'code' => '6109.10.00'],
    ['pattern' => 'hoodie|sweater|sweatshirt|cardigan|pullover|sueter', 'code' => '6110.19.00'],
    ['pattern' => 'jeans|trousers|pants|shorts|pantalon', 'code' => '6203.42.90'],
    ['pattern' => 'dress|skirt|blouse|vestido|falda|blusa', 'code' => '6204.62.90'],
    ['pattern' => 'socks|stockings|calcetines|medias', 'code' => '6115.95.00'],
    ['pattern' => 'jacket|coat|chaqueta|abrigo', 'code' => '6110.19.00'],

    ['pattern' => 'sneaker|running shoe|athletic shoe|tenis deportivo|zapatilla', 'code' => '6404.11.00'],
    ['pattern' => 'boot|bota', 'code' => '6403.91.00'],
    ['pattern' => 'shoe|sandal|slipper|zapato|sandalia|chancleta', 'code' => '6403.99.90'],

    ['pattern' => 'shampoo|conditioner|champu|acondicionador', 'code' => '3305.10.00'],
    ['pattern' => 'deodorant|antiperspirant|desodorante', 'code' => '3307.20.00'],
    ['pattern' => 'makeup|lipstick|mascara|foundation|maquillaje|labial', 'code' => '3304.99.00'],
    ['pattern' => 'moisturizer|serum|sunscreen|skincare|face cream|crema facial|protector solar', 'code' => '3304.99.00'],

    // Printed matter: duty-free and ITBIS-exempt.
    ['pattern' => 'coloring book|libro para colorear|cuaderno para dibujar', 'code' => '4903.00.00'],
    ['pattern' => 'magazine|revista', 'code' => '4902.90.00'],
    ['pattern' => 'paperback|hardcover|textbook|\bbook\b|\blibro\b', 'code' => '4901.99.00'],

    ['pattern' => 'puzzle|rompecabezas', 'code' => '9503.00.60'],
    ['pattern' => 'doll|action figure|muneca|muneco', 'code' => '9503.00.30'],
    ['pattern' => 'lego|building block|model kit|bloques de construccion', 'code' => '9503.00.40'],
    ['pattern' => 'drone|dron', 'code' => '9503.00.94'],
    ['pattern' => 'toy|juguete', 'code' => '9503.00.99'],

    // Sports: soccer, basketball and baseball gear pays 8%, not the 20% of the rest.
    ['pattern' => 'soccer ball|football ball|balon de futbol', 'code' => '9506.62.10'],
    ['pattern' => 'basketball|balon de baloncesto', 'code' => '9506.62.20'],
    ['pattern' => 'baseball glove|baseball bat|softball|guante de beisbol|bate', 'code' => '9506.99.10'],
    ['pattern' => 'baseball', 'code' => '9506.69.20'],
    ['pattern' => 'tennis ball|pelota de tenis', 'code' => '9506.61.00'],
    ['pattern' => 'golf club|palo de golf', 'code' => '9506.31.00'],
    ['pattern' => 'treadmill|dumbbell|kettlebell|yoga mat|exercise|fitness|caminadora|mancuerna', 'code' => '9506.91.10'],

    ['pattern' => 'mattress|colchon', 'code' => '9404.21.00'],
    ['pattern' => 'pillow|cushion|comforter|almohada|edredon', 'code' => '9404.90.00'],
    ['pattern' => 'bed sheet|towel|bedding|sabana|toalla', 'code' => '6302.21.00'],
    ['pattern' => 'cookware|frying pan|saucepan|skillet|sarten|olla|caldero', 'code' => '7323.93.10'],
    ['pattern' => 'dinnerware|\bplate\b|\bmug\b|vajilla|plato|taza', 'code' => '6912.00.00'],
    ['pattern' => 'kitchen knife|chef knife|cuchillo', 'code' => '8211.92.00'],
    ['pattern' => 'cutlery|silverware|fork|spoon|cubierto|tenedor|cuchara', 'code' => '8215.99.00'],
    ['pattern' => 'desk|chair|bookshelf|dresser|escritorio|silla|mueble|estante', 'code' => '9403.60.00'],

    ['pattern' => 'vitamin|supplement|protein powder|collagen|omega ?3|suplemento|vitamina', 'code' => '2106.90.92'],
];
