<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 7/26/18
 * Time: 10:43 PM
 */

include "simple_html_dom.php";
include "db.class.php";

define('DS', DIRECTORY_SEPARATOR);

//Specify content container here, usually for wordpress all article content placed into <div class=entry> or <div class=Event>, or smth else...
define('contentContainer', 'div.entry');

//example of typical wordpress URL to pages catalog: http://linx.net.ua/page/1
//So, in $currentUrl array we'll have 4 components to make up url to specific page:
//domName (domain name), like 'http://linx.net.ua',
//pageCat (pages catalog) - subpath to all pages, like '/page/',
//pageNumber (number of specific page),
//and closing slash '/'
//just implode this array, and it will become url string. If we want to paginate - just increment page number like $currentUrl[1]++

$currentUrl = array (
    'domName' => 'http://linx.net.ua',
    'pageCat' => '/page/',
    'pageNumber' => '1',
    'closingSlash' => '/',
);

//Create new DB Connection
$db = new DB('localhost', 'root', '', 'linx-content');

//This function is for debug purpose. Because checking page for existing is just extra time waste. In reality (with no 404 check)
//script will end up with error while trying to get unexisting page, but all previous pages will be saved.
function httpResponseStatus(string $url) :int {
    $headers = get_headers($url, 1);
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

function getWebsiteIndex(array &$currentUrl) {
    global $db; //database connection, need to be global
    $url = implode('', $currentUrl); //get URL in string format from array:$currentUrl
    $html = new simple_html_dom(); //new simple_html_dom() object
    $nextUrl = null; //default value for nextUrl. we check this value at the end of function, if it !=null, function call itself recursively

    if(httpResponseStatus(implode ('', $currentUrl))!=404) {
        $html->load_file($url);
        $headerLinks = $html->find('h2 a'); //we are looking for <a> nested in <h2> - this is typical for wordpress headers

        if(!empty($headerLinks)) {
            echo 'Page ['.$url.']:'.PHP_EOL;
            foreach ($headerLinks as $element) {
                $articleUrl = $db->escape($element->href);
                $articleHeader = $db->escape($element->innertext);
                //Put URL and header to DB:
                $sql = "INSERT IGNORE INTO articles (url, header) VALUES ('{$articleUrl}', '{$articleHeader}')";
                $db->query($sql);

                echo 'Index saved to DB -> ['.$articleHeader.'|'.$articleUrl.']'.PHP_EOL;
            }
            //exit(); //<- uncomment this to parse just first page
            //let's get url to next page - just increment $currentUrl['pageNumber'] by 1:
            $currentUrl['pageNumber']++;
            $nextUrl = $currentUrl;
        } else {
            $nextUrl = null;
        }
    }

    $html->clear(); //To avoid memory leak
    unset($html);

    //Only if nextUrl is !=null we call function again. If nextUrl == null, we reached at the end of website.
    if ($nextUrl) {
        getWebsiteIndex($nextUrl);
    } else {
        echo 'Indexing process is DONE. '.($currentUrl['pageNumber']-1).' pages processed.'.PHP_EOL.'Next task - grab articles bodies.'.PHP_EOL;
    }
}

function grabArticles() {
    global $db;
    $html = new simple_html_dom(); //new simple_html_dom() object
    $numOfRecords = (integer) ($db->query("SELECT COUNT(*) AS numofrows FROM articles where dt_parsed is null;"))[0]['numofrows']; //Get number of rows in MySQL Table 'articles'

    while($recordWithoutBody = $db->query("select * from articles where dt_parsed is null order by id ASC limit 1;")){
        $url = $recordWithoutBody[0]['url'];
        $id = $recordWithoutBody[0]['id'];
        $html->load_file($url);
        $content = ($html->find(contentContainer, 0))->innertext; //we are looking for <div class=entry> as for default container for content in WP

        echo 'Saving article '.$id.' of '.$numOfRecords.' to DB: ['.$url.']'.PHP_EOL;

        $content = $db->escape($content);
        $sql = "UPDATE articles SET content = '{$content}', dt_parsed = NOW() WHERE id = '{$id}' LIMIT 1;";
        $db->query($sql);

        downloadIMG(grabImages($html));
    }

    $html->clear(); //To avoid memory leak
    unset($html);
}

function grabImages(object $html) :array {
    global $currentUrl;

    //FIRST, we collect paths to images which are _in_ article
    $artImgPathsArray = array(); //array for store urls to images, found in article
    $articleImages = $html->find(contentContainer.' img');
    foreach($articleImages as $artImage) {
        //echo 'Image in article link: '.$artImage->src.PHP_EOL;
        $artImgPathsArray[] = completeUri($currentUrl['domName'], $artImage->src); //Collect all paths of article's images in array, if needed, convert them to full.
    }

    //SECOND, we'll found links to all external images, which can appear bigger version of image _in_ article
    $bigImgLinksArray = array(); //array for store urls to bigger version of images (when smaller image points to it's fullsize version)
    $possibleLinksToBigImages = $html->find(contentContainer.' a'); //Generally speaking, we just found all links <a href=""> in article body
    foreach ($possibleLinksToBigImages as $link) {
        if($link->find('img', 0)){ //if we found IMG tag nested in A tag, it's very possible (but not shure) A is link to bigger version of image, but it necessary to check this...
            $pathToArray = explode('.', $link->href); //explode path, for example: http://site.com/uploads/picture.gif by '.' to array, which helps us get file extension (gif in this case)
            $fileExtension = strtolower($pathToArray[count($pathToArray)-1]);
            if($fileExtension == 'gif' || $fileExtension == 'png' || $fileExtension == 'jpg' || $fileExtension == 'jpeg'
                || $fileExtension == 'bmp' || $fileExtension == 'tiff') {
                //So, <A> tag is really point to some picture, let's save link to array bigImgLinksArray[]
                //echo 'Image linked w/ img in article (BIGGER?): '.$link->href.PHP_EOL;
                $bigImgLinksArray[] = completeUri($currentUrl['domName'], $link->href);
            }
        }
    }

    //And now, let's compare two arrays: $artImgPathsArray and $bigImgLinksArray
    //If some elements (paths) in these arrays will be the same, it means that there is no bigger version of image and this path can be ignored.
    //at the end of function work, we will have third array - with unique paths do download, which we'll pass to download function
    $reallyBigImgPaths = array_diff($bigImgLinksArray, $artImgPathsArray);
    //foreach($reallyBigImgPaths as $value) {echo 'Really BIG IMG path: '.$value.PHP_EOL;}

    $preparedToDownload = array_unique(array_merge($artImgPathsArray, $reallyBigImgPaths));
    foreach($preparedToDownload as $value) {echo 'IMGs to dwnld (in_article img\'s & linked bigger versions, if exists): '.$value.PHP_EOL;}

    return($preparedToDownload);
}

function downloadIMG(array $linksArray): void {

    foreach ($linksArray as $uri) {
        $uriToArray = explode('/', $uri);

        $fileName = array_pop($uriToArray); //get last element of array, this will be file name
        array_shift($uriToArray); //remove 'http:' as first element of array
        array_shift($uriToArray); //then remove '' (empty element) from array
        $folderStructure = $uriToArray;  //now, we have array which represents folder structure begins with smth like 'site.com' (this will be root folder)

        //let's recreate folder structure with mkdir()...
        $fileSystemPath = '.'.DS.implode(DS, $folderStructure).DS;
        if (!is_dir($fileSystemPath)) {
            // dir doesn't exist, make it
            mkdir($fileSystemPath, 0777, true);
        }

        file_put_contents($fileSystemPath.$fileName, file_get_contents($uri));
    }
}

function completeUri($prefix, $abs_OR_relative_URI) {
    //this function completes URI to full form if URI is relative.
    //Example: '/wp-uploads/images/portrait.jpg' will be converted to 'http://website.com/wp-uploads/images/portrait.jpg'
    //variable $prefix must contain something like 'http://website.com'
    if(!preg_match('/^https?:\/\//', $abs_OR_relative_URI)) {
        $abs_OR_relative_URI = $prefix.$abs_OR_relative_URI;
    }
    $fullURI = $abs_OR_relative_URI; //at this moment we can be sure that URI is full.
    return($fullURI);
}

getWebsiteIndex($currentUrl);
grabArticles();