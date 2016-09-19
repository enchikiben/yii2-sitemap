<?php

namespace enchikiben\sitemap\controllers;

use Yii;

class DefaultController extends \yii\base\Controller
{
    public function actionIndex()
    {
        $module = $this->module;
        if (!$sitemapData = $module->cacheProvider->get($module->cacheKey)) {
            $sitemapData = $module->buildSitemap();
            $module->cacheProvider->set($module->cacheKey, $sitemapData, $module->cacheExpire);
        }
        Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
        $headers = Yii::$app->response->headers;
        $headers->add('Content-Type', 'application/xml');
        return $sitemapData;
    }
}