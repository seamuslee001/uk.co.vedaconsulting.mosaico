<?php

//this may not be required as it doesn't appear to be used anywhere?
//require_once 'packages/premailer/premailer.php';

use CRM_Mosaico_ExtensionUtil as E;

/**
 * Class CRM_Mosaico_Utils
 */
class CRM_Mosaico_Utils {

  /**
   * Get a list of layout options.
   *
   * @return array
   *   Array (string $machineName => string $label).
   */
  public static function getLayoutOptions() {
    return array(
      'auto' => E::ts('Automatically select a layout'),
      'bootstrap-single' => E::ts('Single Page (Bootstrap CSS)'),
      'bootstrap-wizard' => E::ts('Wizard (Bootstrap CSS)'),
    );
  }

  /**
   * Get the path to the Mosaico layout file.
   *
   * @return string
   *   Ex: `~/crmMosaico/EditMailingCtrl/mosaico.html`
   * @see getLayoutOptions()
   */
  public static function getLayoutPath() {
    $layout = CRM_Core_BAO_Setting::getItem('Mosaico Preferences', 'mosaico_layout');
    $prefix = '~/crmMosaico/EditMailingCtrl';

    switch ($layout) {
      case '':
      case 'auto':
      case 'bootstrap-single':
        return "$prefix/mosaico.html";

      case 'bootstrap-wizard':
        return "$prefix/mosaico-wizard.html";

      default:
        throw new \RuntimeException("Failed to determine path for Mosaico layout ($layout)");
    }
  }

  /**
   * Determine the URL of the (upstream) Mosaico libraries.
   *
   * @param string $preferFormat
   *   'absolute' or 'relative'.
   * @param string|NULL $file
   *   The file within the Mosaico library.
   * @return string
   *   Ex: "https://example.com/sites/all/modules/civicrm/tools/extension/uk.co.vedaconsulting.mosaico/packages/mosaico/dist".
   */
  public static function getMosaicoDistUrl($preferFormat, $file = NULL) {
    $key = "distUrl";
    if (!isset(Civi::$statics[__CLASS__][$key])) {
      Civi::$statics[__CLASS__][$key] = CRM_Core_Resources::singleton()->getUrl('uk.co.vedaconsulting.mosaico', 'packages/mosaico/dist');
    }
    return self::filterAbsoluteRelative($preferFormat, Civi::$statics[__CLASS__][$key] . ($file ? "/$file" : ''));
  }

  /**
   * Determine the URL of the Mosaico templates folder.
   *
   * @param string $preferFormat
   *   'absolute' or 'relative'.
   * @param string|NULL $file
   *   The file within the template library.
   * @return string
   *   Ex: "https://example.com/sites/all/modules/civicrm/tools/extension/uk.co.vedaconsulting.mosaico/packages/mosaico/templates".
   */
  public static function getTemplatesUrl($preferFormat, $file = NULL) {
    $key = "templatesUrl";
    if (!isset(Civi::$statics[__CLASS__][$key])) {
      Civi::$statics[__CLASS__][$key] = CRM_Core_Resources::singleton()->getUrl('uk.co.vedaconsulting.mosaico', 'packages/mosaico/templates');
    }
    return self::filterAbsoluteRelative($preferFormat, Civi::$statics[__CLASS__][$key] . ($file ? "/$file" : ''));
  }

  /**
   * @param string $preferFormat
   *   'absolute' or 'relative'.
   * @param string $url
   * @return string
   */
  private static function filterAbsoluteRelative($preferFormat, $url) {
    if ($preferFormat === 'absolute' && !preg_match('/^https?:/', $url)) {
      $url = (\CRM_Utils_System::isSSL() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $url;
    }
    return $url;
  }

  public static function getUrlMimeType($url) {
    $buffer = file_get_contents($url);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    return $finfo->buffer($buffer);
  }

  public static function getConfig() {
    static $mConfig = array();

    if (empty($mConfig)) {
      $civiConfig = CRM_Core_Config::singleton();

      $mConfig = array(
        /* base url for image folders */
        'BASE_URL' => $civiConfig->imageUploadURL,

        /* local file system base path to where image directories are located */
        'BASE_DIR' => $civiConfig->imageUploadDir,

        /* url to the static images folder (relative to BASE_URL) */
        'UPLOADS_URL' => "images/uploads/",

        /* local file system path to the static images folder (relative to BASE_DIR) */
        'UPLOADS_DIR' => "images/uploads/",

        /* url to the static images folder (relative to BASE_URL) */
        'STATIC_URL' => "images/uploads/static/",

        /* local file system path to the static images folder (relative to BASE_DIR) */
        'STATIC_DIR' => "images/uploads/static/",

        /* url to the thumbnail images folder (relative to'BASE_URL'*/
        'THUMBNAILS_URL' => "images/uploads/thumbnails/",

        /* local file system path to the thumbnail images folder (relative to BASE_DIR) */
        'THUMBNAILS_DIR' => "images/uploads/thumbnails/",

        /* width and height of generated thumbnails */
        'THUMBNAIL_WIDTH' => 90,
        'THUMBNAIL_HEIGHT' => 90,
      );
    }

    return $mConfig;
  }


  /**
   * handler for upload requests
   */
  public static function processUpload() {
    $config = self::getConfig();

    global $http_return_code;

    $messages = array();
    _mosaico_civicrm_check_dirs($messages);
    if (!empty($messages)) {
      CRM_Core_Error::debug_log_message('Mosaico uploader failed. Check system status for directory errors.');
      $http_return_code = 500;
      return;
    }

    $files = array();

    if ($_SERVER["REQUEST_METHOD"] == "GET") {
      $dir = scandir($config['BASE_DIR'] . $config['UPLOADS_DIR']);

      foreach ($dir as $file_name) {
        $file_path = $config['BASE_DIR'] . $config['UPLOADS_DIR'] . $file_name;

        if (is_file($file_path)) {
          $size = filesize($file_path);

          $file = array(
            "name" => $file_name,
            "url" => $config['BASE_URL'] . $config['UPLOADS_DIR'] . $file_name,
            "size" => $size,
          );

          if (file_exists($config['BASE_DIR'] . $config['THUMBNAILS_DIR'] . $file_name)) {
            $file["thumbnailUrl"] = $config['BASE_URL'] . $config['THUMBNAILS_URL'] . $file_name;
          }

          $files[] = $file;
        }
      }
    }
    elseif (!empty($_FILES)) {
      foreach ($_FILES["files"]["error"] as $key => $error) {
        if ($error == UPLOAD_ERR_OK) {
          $tmp_name = $_FILES["files"]["tmp_name"][$key];

          $file_name = $_FILES["files"]["name"][$key];
          //issue - https://github.com/veda-consulting/uk.co.vedaconsulting.mosaico/issues/28
          //Change file name to unique by adding hash so every time uploading same image it will create new image name
          $file_name = CRM_Utils_File::makeFileName($file_name);

          $file_path = $config['BASE_DIR'] . $config['UPLOADS_DIR'] . $file_name;

          if (move_uploaded_file($tmp_name, $file_path) === TRUE) {
            $size = filesize($file_path);

            $image = new Imagick($file_path);

            $image->resizeImage($config['THUMBNAIL_WIDTH'], $config['THUMBNAIL_HEIGHT'], Imagick::FILTER_LANCZOS, 1.0, TRUE);
            // $image->writeImage( $config['BASE_DIR'] . $config[ THUMBNAILS_DIR ] . $file_name );
            if ($f = fopen($config['BASE_DIR'] . $config['THUMBNAILS_DIR'] . $file_name, "w")) {
              $image->writeImageFile($f);
            }
            $image->destroy();

            $file = array(
              "name" => $file_name,
              "url" => $config['BASE_URL'] . $config['UPLOADS_DIR'] . $file_name,
              "size" => $size,
              "thumbnailUrl" => $config['BASE_URL'] . $config['THUMBNAILS_URL'] . $file_name,
            );

            $files[] = $file;
          }
          else {
            $http_return_code = 500;
            return;
          }
        }
        else {
          $http_return_code = 400;
          return;
        }
      }
    }

    header("Content-Type: application/json; charset=utf-8");
    header("Connection: close");

    echo json_encode(array("files" => $files));
    CRM_Utils_System::civiExit();
  }

  /**
   * handler for img requests
   */
  public static function processImg() {
    if ($_SERVER["REQUEST_METHOD"] == "GET") {
      $method = $_GET["method"];

      $params = explode(",", $_GET["params"]);

      $width = (int) $params[0];
      $height = (int) $params[1];

      if ($method == "placeholder") {
        $image = new Imagick();

        $image->newImage($width, $height, "#707070");
        $image->setImageFormat("png");

        $x = 0;
        $y = 0;
        $size = 40;

        $draw = new ImagickDraw();

        while ($y < $height) {
          $draw->setFillColor("#808080");

          $points = array(
            array("x" => $x, "y" => $y),
            array("x" => $x + $size, "y" => $y),
            array("x" => $x + $size * 2, "y" => $y + $size),
            array("x" => $x + $size * 2, "y" => $y + $size * 2),
          );

          $draw->polygon($points);

          $points = array(
            array("x" => $x, "y" => $y + $size),
            array("x" => $x + $size, "y" => $y + $size * 2),
            array("x" => $x, "y" => $y + $size * 2),
          );

          $draw->polygon($points);

          $x += $size * 2;

          if ($x > $width) {
            $x = 0;
            $y += $size * 2;
          }
        }

        $draw->setFillColor("#B0B0B0");
        $draw->setFontSize($width / 5);
        $draw->setFontWeight(800);
        $draw->setGravity(Imagick::GRAVITY_CENTER);
        $draw->annotation(0, 0, $width . " x " . $height);

        $image->drawImage($draw);

        header("Content-type: image/png");

        echo $image;
      }
      else {
        $file_name = $_GET["src"];

        $path_parts = pathinfo($file_name);

        switch ($path_parts["extension"]) {
          case "png":
            $mime_type = "image/png";
            break;

          case "gif":
            $mime_type = "image/gif";
            break;

          default:
            $mime_type = "image/jpeg";
            break;
        }

        $file_name = $path_parts["basename"];

        $image = self::resizeImage($file_name, $method, $width, $height);

        $expiry_time = 2592000;  //30days (60sec * 60min * 24hours * 30days)
        header("Pragma: cache");
        header("Cache-Control: max-age=" . $expiry_time . ", public");
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $expiry_time) . ' GMT');
        header("Content-type:" . $mime_type);

        echo $image;
      }
    }
    CRM_Utils_System::civiExit();
  }

  /**
   * function to resize images using resize or cover methods
   */
  public static function resizeImage($file_name, $method, $width, $height) {
    $mobileMinWidth = 246;
    $config = self::getConfig();

    if (file_exists($config['BASE_DIR'] . $config['STATIC_DIR'] . $file_name)) {
      //use existing file
      $image = new Imagick($config['BASE_DIR'] . $config['STATIC_DIR'] . $file_name);

    }
    else {

      $image = new Imagick($config['BASE_DIR'] . $config['UPLOADS_DIR'] . $file_name);

      if ($method == "resize") {
        $resize_width = $width;
        $resize_height = $image->getImageHeight();
        if ($width < $mobileMinWidth) {
          // DS: resize images to higher resolution, for images with lower width than needed for mobile devices
          // DS: FIXME: only works for 'resize' method, not 'cover' methods.
          // Partially resolves - https://github.com/veda-consulting/uk.co.vedaconsulting.mosaico/issues/50
          $fraction = ceil($mobileMinWidth / $width);
          $resize_width = $resize_width * $fraction;
          $resize_height = $resize_height * $fraction;
        }
        // We get 0 for height variable from mosaico
        // In order to use last parameter(best fit), this will make right scale, as true in 'resizeImage' menthod, we can't have 0 for height
        // hence retreiving height from image
        // more details about best fit http://php.net/manual/en/imagick.resizeimage.php
        $image->resizeImage($resize_width, $resize_height, Imagick::FILTER_LANCZOS, 1.0, TRUE);
      }
      else {
        // assert: $method == "cover"
        $image_geometry = $image->getImageGeometry();

        $width_ratio = $image_geometry["width"] / $width;
        $height_ratio = $image_geometry["height"] / $height;

        $resize_width = $width;
        $resize_height = $height;

        if ($width_ratio > $height_ratio) {
          $resize_width = 0;
        }
        else {
          $resize_height = 0;
        }

        $image->resizeImage($resize_width, $resize_height,
          Imagick::FILTER_LANCZOS, 1.0);

        $image_geometry = $image->getImageGeometry();

        $x = ($image_geometry["width"] - $width) / 2;
        $y = ($image_geometry["height"] - $height) / 2;

        $image->cropImage($width, $height, $x, $y);
      }
      //save image for next time so don't need to resize each time
      if ($f = fopen($config['BASE_DIR'] . $config['STATIC_DIR'] . $file_name, "w")) {
        $image->writeImageFile($f);
      }

    }

    return $image;
  }

}
