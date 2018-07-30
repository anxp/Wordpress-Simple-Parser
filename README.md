# Wordpress Simple Parser
### (with image and image-linked-to-image download)
Simple website parser suitable for Wordpress blogs (but maybe for other websites with some edits).
Parser uses "Simple HTML DOM" library.

Parser need MySQL database, query for creating correct DB see at the botoom of this readme.

How it works?

1. User specifies URL to pages catalog of wordpress site. Usually it is like http://website.com/page/
2. User specifies DIV container which contains article body (`<div class=entry>` or `<div class=Event>`)
3. Script looks throw all pages from `site.com/page/first_page->site.com/page/last_page` and gets header and
links to full article. Save them to DB.
4. Next, script get links to full article from DB, and looks for specified DIV container on those pages,
copy full articles and saves to DB.
5. When found an image, script downloads it, and also looks maybe there is bigger images linked to this image.
If bigger image exists, it downloads too.

####SQL query for creation correct table to work with this script:

CREATE TABLE `articles` (

  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  
  `url` varchar(255) NOT NULL,
  
  `header` varchar(255) NOT NULL,
  
  `content` text,
  
  `dt_parsed` timestamp NULL DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  
  UNIQUE KEY `articles_UN` (`url`)

) 
ENGINE=InnoDB DEFAULT CHARSET=utf8;