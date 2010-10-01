<?php

/****************************************************************
 *
 *  Copyright 2010 The President and Fellows of Harvard College
 *  Copyright 2010 Modo Labs Inc.
 *
 *****************************************************************/


class HARVARD_DINING {

public function getMealData($baseUrl, $dateToday, $mealExtension)
{
$urlLink = $baseUrl.$dateToday.$mealExtension;
$contents = file_get_contents($urlLink);

return $contents;
}
}



class MenuItem {

    public $mealDate;
    public $id;
    public $name;
    public $meal;
    public $hall;
    public $foodType;
    public $servingSize;
    public $servingUnit;
    public $type;

public function __construct($data) {
    
        date_default_timezone_set('America/New_York');
        $this->mealDate = strtotime($data[0]);
        $this->id = $data[1];
        $this->name = $data[2];
        $this->meal = $data[3];
        $this->hall = $data[4];
        $this->foodType = $data[5];
        $this->servingSize = $data[6];
        $this->servingUnit = $data[7];
        $this->type = $data[8];
}

/* * **
 * Function to convert menu item food type codes into human-readable string
 */
public function getFoodTypeAsName() {

$foodTypeName;

switch ($this->foodType) {

case "01":
$foodTypeName = "Breakfast Meats";
break;
case "02":
$foodTypeName = "Breakfast Entrees";
break;
case "03":
$foodTypeName = "Breakfast Bakery";
break;
case "04":
$foodTypeName = "Breakfast Misc";
break;
case "05":
$foodTypeName = "Breakfast Breads";
break;
case "06":
$foodTypeName = "Seasonal";
break;
case "07":
$foodTypeName = "Today's Soup";
break;
case "08":
$foodTypeName = "Made to Order Bar";
break;
case "09":
$foodTypeName = "Brunch";
break;
case "10":
$foodTypeName = "Salad Bar";
break;
case "11":
$foodTypeName = "Sandwich Bar";
break;
case "12":
$foodTypeName = "Entrees";
break;
case "13":
$foodTypeName = "Accompaniments";
break;
case "14":
$foodTypeName = "Starch & Potatoes";
break;
case "15":
$foodTypeName = "Vegetables";
break;
case "16":
$foodTypeName = "Fruit, Fresh, Caned & Frozen";
break;
case "17":
$foodTypeName = "Desserts";
break;
case "18":
$foodTypeName = "Bread, Rolls, Misc Bakery";
break;
case "19":
$foodTypeName = "From the Grille";
break;
case "20":
$foodTypeName = "Bean, Whole Grain";
break;
case "21":
$foodTypeName = "Basic Food Table";
break;
case "22":
$foodTypeName = "Brown Rice Station";
break;
case "23":
$foodTypeName = "Make or Build Your Own";
break;
case "24":
$foodTypeName = "Special Bars - Board Menu";
break;
case "25":
$foodTypeName = "Culinary Display";
break;
case "27":
$foodTypeName = "In Addition at Annenberg";
break;
case "28":
$foodTypeName = "Bag Lunches";
break;
case "29":
$foodTypeName = "Production Salads";
break;
case "30":
$foodTypeName = "A C I";
break;
case "31":
$foodTypeName = "Chef's Choice";
break;
case "40":
$foodTypeName = "Festive Meals";
break;
case "41":
$foodTypeName = "Kosher Table";
break;
case "42":
$foodTypeName = "Fly-By";
break;
case "43":
$foodTypeName = "Continental Breakfast";
break;
case "44":
$foodTypeName = "Vegetarian Station";
break;
case "45":
$foodTypeName = "Pasta a la Carte";
break;
case "46":
$foodTypeName = "Love Your Heart Menu";
break;
case "90":
$foodTypeName = "Brain Break";
break;
case "99":
$foodTypeName = "Misc. Supplies";
break;
default:
$foodTypeName = "Other";
}
return $foodTypeName;
}

/**
 * Returns an array of values in the original order for writing out as CSV
 */
public function toArray() {

    $values = array();
    $values[] = date("m/d/Y", $this->mealDate);
    $values[] = $this->id;
    $values[] = $this->name;
    $values[] = $this->meal;
    $values[] = $this->hall;
    $values[] = $this->getFoodTypeAsName();
    $values[] = $this->servingSize;
    $values[] = $this->servingUnit;
    $values[] = $this->type;

    return $values;
}

/**
 * Order of meals
 */
public function getMealOrder() {
    $mealOrder = 0;
    switch ($this->meal) {
        case "BRK":
            $mealOrder = 0;
            break;
        case "LUN":
            $mealOrder = 1;
            break;
        case "DIN":
            $mealOrder = 2;
            break;
        default:
            $mealOrder = 3;
    }

    return $mealOrder;
}

/**
 * Comparator function used for sorting
 */
static function compare($obj1, $obj2) {

    if ($obj1->getMealOrder() != $obj2->getMealOrder()) {
        return ($obj1->getMealOrder() < $obj2->getMealOrder()) ? -1 : 1;
    }

    if ($obj1->foodType != $obj2->foodType) {
        return ($obj1->foodType < $obj2->foodType) ? -1 : 1;
    }

    return strcmp($obj1->name, $obj2->name);
    }
}



class DINING_DATA {

    public function createDiningFlatFile($local_file) {

        $handle1 = fopen($local_file, 'w');
        $contents = file_get_contents(DINING_MENU_RAW_FILE);
        fwrite($handle1, $contents);
        fclose($handle1);
        
        $handle = fopen($local_file, "r");

        $menus = array();
        while (($data = fgetcsv($handle)) !== FALSE) {
            $menu_item = new MenuItem($data);
            $menu_key = $menu_item->mealDate;

            if (!array_key_exists($menu_key, $menus)) {
                $menus[$menu_key] = array();
            }

            $menus[$menu_key][] = $menu_item;
        }

        fclose($handle);

        /*
        * Write out a file for each date in appropriate sorted order.
        */

        foreach ($menus as $menuDate => $menuItemList) {
        // Format as YYYY-MM-DD for file name
            $filename = DINING_MENU_DIRECTORY .date("Y-m-d", $menuDate).".csv";
            $handle = fopen($filename, "w");
            usort($menuItemList, array("MenuItem", "compare"));

            if ($handle !== FALSE) {
              foreach ($menuItemList as $menuItem) {

                fputcsv($handle, $menuItem->toArray());
              }
              
              fclose($handle);
            }
       }
  }


    public function getDiningData($date, $mealTime) {

        $menu = array();
        $day = $date;
        $filename = DINING_MENU_DIRECTORY .$day .".csv";

        self::createDiningFLatFile(DINING_MENU_FLAT_FILE);

        if (file_exists($filename))
            $handle = fopen($filename, "r");

        else
            return $menu;

        while (($data = fgetcsv($handle)) !== FALSE) {
            $menu_item = new MenuItem($data);

            $menu_item_array = array();
            $menu_item_array['item'] = $menu_item->name;
            $menu_item_array['meal'] = $menu_item->meal;
            $menu_item_array['date'] = date('Y-m-d',$menu_item->mealDate);
            $menu_item_array['id'] = $menu_item->id;
            $menu_item_array['category'] = $menu_item->foodType;
            $menu_item_array['servingSize'] = $menu_item->servingSize;
            $menu_item_array['servingUnit'] = $menu_item->servingUnit;
            $menu_item_array['type'] = $menu_item->type;

            if ($mealTime == $menu_item->meal)
                $menu[] = $menu_item_array;
        }

    return $menu;
    }
}
?>
