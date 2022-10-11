<?
namespace App;
use Intervention\Image\ImageManagerStatic as IMS;

class ImageProcessor
{
	
	const IMG_PATH = '/upload/resized/';

	/**
	 *  Обрезаем изображение по заданному ID и размерам и возвращаем его в статике
	 * @param $imageID - ID исходного изображения
	 * @param $width - ширина обрезанного изображения
	 * @param $height - высота обрезанного изображения
	 * @param int $resizeType - тип обрезки (BX_RESIZE_IMAGE_EXACT, BX_RESIZE_IMAGE_PROPORTIONAL, BX_RESIZE_IMAGE_PROPORTIONAL_ALT)
	 * @return string
	 */
	public static function getSimpleImage($imageID, $width, $height, $resizeType = BX_RESIZE_IMAGE_PROPORTIONAL)
	{
		$arImage = \CFile::ResizeImageGet(
			$imageID,
			["width" => $width, "height" => $height],
			$resizeType,
			false,
			false,
			true
		);
		return $arImage["src"];
	}


    /**
     * Для обработчика OnAfterIBlockElementUpdate
     * @param $arFields
     */
    public static function imagesUpdate($arFields)
    {

        $arImagesInFolder = self::getIDImagesArrayInFolder($arFields['IBLOCK_ID'], $arFields['ID']);

        // DETAIL_PICTURE
        if ((!$arFields['DETAIL_PICTURE_ID'] && $arFields['DETAIL_PICTURE']['del'] == 'Y') || ($arFields['DETAIL_PICTURE_ID'] && $arFields['DETAIL_PICTURE']['old_file']))
        {
            // если удалилии или изменили детальную картинку
            // Удалим старую детальную картинку
            self::deleteImagesIn($arFields['IBLOCK_ID'], $arFields['ID'], $arFields['DETAIL_PICTURE']['old_file']);
        }
        // если что-то еще осталось необработанным, то эти файлы нужно удалить
        if ($arImagesInFolder)
        {
            foreach ($arImagesInFolder as $itemID)
            {
                self::deleteImagesIn($arFields['IBLOCK_ID'], $arFields['ID'], $itemID);
            }
        }
    }


    /**
     * Для обработчика OnBeforeIBlockElementDelete
     * @param $arFields
     */
   public static function imagesDelete($arFields){
        self::deleteImagesIn($arFields['IBLOCK_ID'], $arFields['ID']);
   }


    /**
     * TODO не работает как надо с ватермарками
     * На основе массива из id изображений создает их копии в заданном размере
     * и размещает их по пути /upload/resized/IBLOCK_ID/SECTION_ID/ITEM_ID/
     * @param int $arImages - id изображения
     * @param int $width - ширина изображения
     * @param int $height - высота изображения
     * @param int $IBLOCK_ID - id инфоблока
     * @param int $ELEMENT_ID - id элемента инфоблока для которого берется изображение
     * @param bool $isWatermark - нужно ли накладывать ватермарк на изображение
     * @return array  массив ссылок на изображения заданного разрешения в кеше со static домена
     * (или описание ошибки в элементе массива если что-то не удалось)
     */
    /*public static function getArImages($arImages, $width, $height, $IBLOCK_ID, $ELEMENT_ID, $isWatermark=false){
        $result = array();
        foreach ($arImages as $id){
            $result[] = self::getImage($id, $width, $height, $IBLOCK_ID, $ELEMENT_ID, $isWatermark);
        }
        return $result;
    }*/

    /**
     * На основе входного изображения создает его копию в заданном размере
     * и размещает ее по пути /upload/resized/IBLOCK_ID/ITEM_ID/
     * @param int $imageID - id изображения
     * @param array $arDimensions - массив размеров изображения вида array("width"=>WIDTH, "height"=>HEIGHT)
     * @param $resizeType - Тип масштабирования (аналогично ResizeImageGet)
     * @param string $IBLOCK_ID - id инфоблока
     * @param string $ELEMENT_ID - id элемента инфоблока для которого берется изображение
     * @param bool $isWatermark - нужно ли накладывать ватермарк на изображение
     * @return string  Ссылка на изображение заданного разрешения в кеше со static домена
     * (или описание ошибки если что-то не удалось)
     */
    public static function getImage($imageID, $arDimensions, $resizeType = BX_RESIZE_IMAGE_PROPORTIONAL, $IBLOCK_ID = '', $ELEMENT_ID = '', $isWatermark = false, $jpegQuality = 70)
    {
        $width = $arDimensions['width'];
        $height = $arDimensions['height'];

        if (!$imageID) 
            return 'Empty imageID';

        $sourceImagePath = \CFile::GetPath($imageID);

        if (is_null($sourceImagePath)) 
            return 'No such image';

        //проверяем существует ли физически исходный файл
        if (!file_exists($_SERVER['DOCUMENT_ROOT'] . $sourceImagePath))
            return 'Source image file not found';

        // если нельзя сформировать путь к папке для сохранения картинки
        // то используем стандартный битриксовский ресайз
        if (empty($IBLOCK_ID) || empty($ELEMENT_ID)) {
            return self::getSimpleImage($imageID, $width, $height, $resizeType);
        }

//        $extension = pathinfo($sourceImagePath, PATHINFO_EXTENSION); // Убрано сохранение картинок в исходном формате
        $extension = 'jpg'; // Все картинки будут сохранены в jpg
        if ($path = self::imageExist($imageID, $width, $height, $IBLOCK_ID, $ELEMENT_ID, $extension, $isWatermark)){
            return $path;
        } else {
            // Если файла нет, то создадим
            // Вернем сообщение об ошибке, если класс не найден
            if (!class_exists('Intervention\Image\ImageManagerStatic')){
                return 'Class Intervention\Image\ImageManagerStatic not found. Can not create image without this class.';
            }

            IMS::configure(array('driver' => 'imagick')); // драйвер

            // взяли картинку в кеше битрикса
            $img = IMS::make($_SERVER['DOCUMENT_ROOT'] . $sourceImagePath);

            // сменили размер
            if ($resizeType == BX_RESIZE_IMAGE_EXACT) //с обрезкой

                $img->fit($width, $height, function ($constraint) {
                    $constraint->upsize();
                });
                //$img->fit($width, $height);

            elseif ($resizeType == BX_RESIZE_IMAGE_PROPORTIONAL) //с сохранением пропорций

                $img->resize($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });

            // сохранили в свой кеш
            $newFilePath = self::buildFilePath($IBLOCK_ID, $ELEMENT_ID);
            if (!file_exists($_SERVER['DOCUMENT_ROOT'] . $newFilePath)){
                if (!mkdir($_SERVER['DOCUMENT_ROOT'] . $newFilePath, 0755, true)){
                    return 'Folder creation failed';
                }
            }
            $newFilePath .= self::buildFileName($imageID, $width, $height, $extension, $isWatermark);
            $img->save($_SERVER['DOCUMENT_ROOT'] . $newFilePath, $jpegQuality);

            return $newFilePath;
        }
    }


    /**
     * Проверит есть ли по пути /upload/resized/IBLOCK_ID/ELEMENT_ID/
     * изображение fileName-width-height.extension
     * @param $fileName
     * @param $width
     * @param $height
     * @param $IBLOCK_ID
     * @param $ELEMENT_ID
     * @param $extension - расширение файла для формирования пути
     * @param $is_watermark - нужно ли накладывать ватермарк на изображение
     * @return bool|string
     */
    private static function imageExist($fileName, $width, $height, $IBLOCK_ID, $ELEMENT_ID, $extension, $is_watermark)
    {
        $filePath = self::buildFilePath($IBLOCK_ID, $ELEMENT_ID);
        $editedFileName = self::buildFileName($fileName, $width, $height, $extension, $is_watermark);
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . $filePath . $editedFileName))
        {
            return $filePath . $editedFileName;
        }  else
            return false;
    }


    /**
     * Удаляет картинки, связанные с товаром
     * по пути /upload/resized/IBLOCK_ID/ELEMENT_ID/
     * @param $IBLOCK_ID - инфоблок удаляемого элемента
     * @param $ELEMENT_ID - id удаляемого элемента
     * @param string $ID - если не пустой, то удалит только все файлы, связанные с этим ID в папке, иначе удалит еще и саму папку
     * @return bool - в случае ошибки вернет false
     */
    private static function deleteImagesIn($IBLOCK_ID, $ELEMENT_ID, $ID = '')
    {
        if (empty($IBLOCK_ID) || empty($ELEMENT_ID)) return false; // TODO тут бы может эксепшн вызвать?

        $filePath = self::buildFilePath($IBLOCK_ID, $ELEMENT_ID);

        // Удаляет все файлы, связанные с товаром
        if (is_dir($_SERVER['DOCUMENT_ROOT'] . $filePath))
        {
            array_map("unlink", glob($_SERVER['DOCUMENT_ROOT'] . $filePath . $ID . "*"));
            if (!$ID)
            {
                // удаляем директории в которых лежали файлы, если удаляем все картинки
                $removedLevels = self::directoryRemove($_SERVER['DOCUMENT_ROOT'] . $filePath, 2);
                if ($removedLevels > 0) 
                    return true;
                else 
                    return false;
            } 
            else
                return true;
        } 
        else 
            return false;
    }


    /**
     * Построит путь типа /upload/resized/IBLOCK_ID/ELEMENT_ID/
     * @param $IBLOCK_ID
     * @param $IBLOCK_ID
     * @param $ELEMENT_ID
     * @return string
     */
    private static function buildFilePath($IBLOCK_ID, $ELEMENT_ID){
        //return self::IMG_PATH . self::$arPath[$IBLOCK_ID] . "/" . $ELEMENT_ID . "/";
        return self::IMG_PATH . $IBLOCK_ID . "/" . $ELEMENT_ID . "/";
    }


    /**
     * Построит имя файла типа fileName-width-height.extension
     * @param $fileName
     * @param $width
     * @param $height
     * @param $extension - расширение файла
     * @param $is_watermark - при наличии ватермарки добавит постфикс к имени файла
     * @return string
     */
    private static function buildFileName($fileName, $width, $height, $extension, $is_watermark){
        $postfix = '';
        if ($is_watermark) $postfix = '-wm';
        return $fileName . "-" . $width . "x" . $height . $postfix . "." . $extension;
    }


    /**
     * Пытается удалить $count директорий по заданному пути если они пустые
     * Удалит пустые директории
     * @param $path - путь до папки (должен заканчиваться на /)
     * @param $count - количество уровней вложенности на удаление
     * @return bool - в случае удаления папки вернет true, иначе false
     */
    private static function directoryRemove($path, $count){
        $removedLevels = 0;
        for ($i = 0; $i < $count; $i++){
            $fileCount = count(glob($path . "*"));
            if  ($fileCount == 0){
                if(!rmdir($path)){
                    echo "Не удалось удалить директорию <br>";
                    break;
                } else {
                    $removedLevels++;
                }
            } else break;
            $path = dirname($path) . '/';
        }
        return $removedLevels;
    }


    /**
     * Достанет из папки массив ID всех фоток внутри папки /$IBLOCK_ID/$ELEMENT_ID/
     * @param $IBLOCK_ID
     * @param $ELEMENT_ID
     * @return array
     */
    private static function getIDImagesArrayInFolder($IBLOCK_ID, $ELEMENT_ID){
        $filePath = self::buildFilePath($IBLOCK_ID, $ELEMENT_ID);
        $result = glob($_SERVER['DOCUMENT_ROOT'] . $filePath  . "*");

        $modifiedResult = array();
        foreach ($result as $path)
        {
            $id = explode('-', pathinfo($path, PATHINFO_FILENAME))[0];
            $modifiedResult[$id] = $id;
        }
        return array_unique($modifiedResult);
    }
    
}