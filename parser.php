<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 7/26/18
 * Time: 10:43 PM
 */

include "simple_html_dom.php";
include "db.class.php";

//$URL = 'http://kaplunenko.name/page/1/';
//baseURL - is URL to wordpress pages WITHOUT number at the end:
$baseURL = 'http://linx.net.ua/page/';

$currentUrl = array (
    0 => '',
    1 => '',
    2 => '',
);

//So, in $currentUrl array we'll have 3 components to make up url to specific page: baseUrl, page number, and closing slash '/'
//just implode it and it will become url string. If we want to paginate - just increment page number like $currentUrl[1]++
$currentUrl[0] = $baseURL;
$currentUrl[1] = 1;
$currentUrl[2] = '/';

//Create new DB Connection
$db = new DB('localhost', 'root', 'ketchup', 'linx-content');

//This function is for debug purpose. Because checking page for existing is just extra time waste. In reality (with no 404 check)
//script will end up with error while trying to get unexisting page, but all previous pages will be saved.
function httpResponseStatus(string $url) :int {
    $headers=get_headers($url, 1);
    if($headers[0] == 'HTTP/1.1 200 OK') {
        return 200;
    } elseif ($headers[0] == 'HTTP/1.1 301 Moved Permanently') {
        return 301;
    } elseif ($headers[0] == 'HTTP/1.1 404 Not Found') {
        return 404;
    } else {
        return 0;
    }
}

function getWebsiteIndex (array &$currentUrl) {
    $url = implode('', $currentUrl); //get URL in string format from array:$currentUrl
    $html = new simple_html_dom(); //new simple_html_dom() object
    $nextUrl = null; //default value for nextUrl. we check this value at the end of function, if it !=null, function call itself recursively
    global $db; //database connection, need to be global

    if(httpResponseStatus(implode ('', $currentUrl))!=404) {
        $html->load_file($url);
        $headerLinks = $html->find('h2 a'); //we are looking for <a> nested in <h2> - this is typical for wordpress headers

        if(!empty($headerLinks)) {
            echo 'Page ['.$url.']:'.PHP_EOL;
            foreach ($headerLinks as $element) {
                $article_url = $db->escape($element->href);
                $article_header = $db->escape($element->innertext);
                //Put URL and header to DB:
                $sql = "INSERT IGNORE INTO articles (url, header) VALUES ('{$article_url}', '{$article_header}')";
                $db->query($sql);

                echo 'Index saved to DB -> ['.$article_header.'|'.$article_url.']'.PHP_EOL;
            }
//exit(); //let's parse just first page
            //let's get url to next page - just increment $currentUrl[1] by 1:
            $currentUrl[1]++;
            $nextUrl = $currentUrl;
        } else {
            $nextUrl = null;
        }
    }

    $html->clear(); //To avoid memory leak
    unset($html);

    //Only if nextUrl is !=null we call function again. If nextUrl == null, we reached at the end of website.
    if ($nextUrl) {
        getWebsiteIndex ($nextUrl);
    } else {
        echo 'Indexing process is DONE. '.($currentUrl[1]-1).' pages processed.'.PHP_EOL.'Next task - grab articles bodies.'.PHP_EOL;
    }
}

function grabArticles() {
    global $db;
    $html = new simple_html_dom(); //new simple_html_dom() object
    $numOfRecords = (integer) ($db->query("SELECT COUNT(*) AS numofrows FROM articles where dt_parsed is null;"))[0]['numofrows']; //Get number of rows in MySQL Table articles

    while($recordWithoutBody = $db->query("select * from articles where dt_parsed is null order by id ASC limit 1;")){
        $url = $recordWithoutBody[0]['url'];
        $id = $recordWithoutBody[0]['id'];
        $html->load_file($url);
        $content = ($html->find('div.entry', 0))->innertext; //we are looking for <div class=entry> as for default container for content in WP

        echo 'Saving article '.$id.' of '.$numOfRecords.' to DB: ['.$url.']'.PHP_EOL;

        $content = $db->escape($content);
        $sql = "UPDATE articles SET content = '{$content}', dt_parsed = NOW() WHERE id = '{$id}' LIMIT 1;";
        $db->query($sql);
        //var_dump($content);
        //break;
    }
}

//getWebsiteIndex($currentUrl);
grabArticles();