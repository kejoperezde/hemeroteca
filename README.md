# Hemeroteca

<p align="center">
	<img src="public/logfisvertical.png" alt="Logo vertical" width="160">
</p>

Aplicación web para registrar, organizar y consultar fuentes digitales con su respaldo.

Está construida con Laravel + Inertia + React, y permite trabajar con capturas en formato WACZ para consulta y descarga posterior.

## Tecnologías

- Backend: Laravel 13 (PHP 8.3)
- Frontend: React 19 + TypeScript + Inertia.js
- Build de frontend: Vite
- Estilos: Tailwind CSS
- Base de datos: MySQL/MariaDB/PostgreSQL (según configuración de Laravel)
- Pruebas: Pest

## Funcionalidades básicas

- Registro de fuentes (URL, título, descripción y contenido).
- Asociación de etiquetas y número de oficio.
- Carga de archivos WACZ como respaldo de la fuente.
- Vista y descarga de respaldo guardado.
- Filtros y búsqueda por texto, fechas y etiquetas.

## Estructura principal

- `app/Http/Controllers/HemerotecaController.php`: lógica principal de hemeroteca.
- `routes/web.php`: rutas de hemeroteca y respaldo.
- `resources/js/pages/hemeroteca.tsx`: interfaz principal de consulta y filtros.
- `database/migrations/2026_03_12_170204_tablas.php`: tablas de fuentes, etiquetas y oficios.
- `tests/Feature/HemerotecaPageTest.php`: pruebas de acceso y flujo principal.

## Requisitos

- PHP 8.3+
- Composer
- Node.js 20+ y npm
- Base de datos configurada en `.env`

## Instalación

1. Instalar dependencias de PHP:

	```bash
	composer install
	```

2. Crear archivo de entorno:

	```bash
	cp .env.example .env
	```

	En Windows PowerShell puedes usar:

	```powershell
	Copy-Item .env.example .env
	```

3. Generar clave de aplicación:

	```bash
	php artisan key:generate
	```

4. Configurar base de datos en `.env` y ejecutar migraciones:

	```bash
	php artisan migrate
	```

5. Instalar dependencias de frontend:

	```bash
	npm install
	```

También puedes usar el script rápido de Composer:

```bash
composer run setup
```

## Ejecución en desarrollo

Comando integrado (servidor Laravel + cola + Vite):

```bash
composer run dev
```

Alternativa en dos terminales:

```bash
php artisan serve
```

```bash
npm run dev
```

## Pruebas

Ejecutar pruebas:

```bash
php artisan test
```

Con validaciones de estilo incluidas en el flujo de Composer:

```bash
composer run test
```