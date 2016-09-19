# yii2-sitemap

Installation
------------
The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

```
php composer.phar require --prefer-dist "enchikiben/yii2-sitemap" "*"
```
or

```json
"enchikiben/yii2-sitemap" : "*"
```

Configure
---------

```php
'modules' => [
    'sitemap' => [
        'class' => 'enchikiben\sitemap\Sitemap',
        'controllerDirAlias' => '@frontend/controllers'
    ],
],
```

Add a new rule for `urlManager` of your application's configuration file, for example:

```php
'urlManager' => [
    'rules' => [
        ['pattern' => 'sitemap', 'route' => 'sitemap/default/index', 'suffix' => '.xml'],
    ],
],
```

Use
---
```php
class SiteController extends Base
{

    /**
     * @sitemap priority=1
     */
    public function actionIndex()
    {
    } 

    /**
     * @sitemap priority=0.8
     */
    public function actionConfidentiality()
    {
    }
}
```

or

```php
class NewsController extends Base
{
    /**
     * @sitemap priority=0.5 changefreq=monthly route=['/news/view','id'=>$model->id] model=common\models\News condition=['status'=>1]
     */
    public function actionView($id)
    {
        
    }
}
```