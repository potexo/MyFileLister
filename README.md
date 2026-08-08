# MyFileLister
	* MyFileLister es una sencilla herramienta contenida en un único archivo php que permite mostrar el contenido de cualquier carpeta (y sus subcarpetas) en un servidor web. 
	
## Requisitos
	* Requiere php 7.3 ó superior.
	* Requiere tener instalada la extensión php-zip (Ejemplo: sudo apt install php7.3-zip)

## Características destacadas
	* Soporta cambio dinámico de tema visual (claro/oscuro).
	* Soporta descarga de ficheros individuales.
	* Soporta descarga masiva de los ficheros seleccionados (comprimidos en un único 'zip').
	* Soporta ordenación por nombre, tamaño o fecha de modificación.
	* Soporta filtrado instantáneo por nombre (sin recarga de la página).
	* No requiere instalación, tan solo depositar en archivo 'index_my_file_lister_<version>.php' en la carpeta deseada y renombrarlo si es necesario.

## Ajustes por defecto
	* Descargas masivas habilitadas para ficheros de tamaño < 500Mb.
	* Extensiones de archivos permitidas: ['iso','dmg','apk','msi','cmd','bat','sh','jar','7z','exe','gz','html','htm','txt','jpg','jpeg','png','gif','pdf','zip','rar','doc','docx','xls','xlsx','csv','ppt','pptx','ods','odt','odp']
	Además de las extensiones indicadas, también se admiten ficheros sin extensión que no estén ocultos.
	
## Personalización de ajustes 
La personalización de los puntos indicados anteriormente puede efectuarse a nivel del propio fichero php, en la sección "2. CONFIGURACIÓN Y PARÁMETROS", modificando las variables que se indican:
	* Tamaño máximo de fichero para descargas masivas: $maxZipSize
	* Extensiones de ficheros permitidas: $allowedExts
  