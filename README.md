# Hemeroteca

<p align="center">
  <img src="public/logfisvertical.png" alt="Logo Hemeroteca" width="160">
</p>

[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](#)
[![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)](#)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178C6?logo=typescript&logoColor=white)](#)
[![Vite](https://img.shields.io/badge/Vite-8-646CFF?logo=vite&logoColor=white)](#)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-4-06B6D4?logo=tailwindcss&logoColor=white)](#)

Aplicacion web para registrar, organizar y consultar fuentes digitales con su respaldo.

El proyecto esta construido con Laravel + Inertia + React, y permite trabajar con capturas en formato WACZ para consulta y descarga posterior.

---

## Tabla de contenidos

- [Vision general](#vision-general)
- [Tecnologias](#tecnologias)
- [Funcionalidades](#funcionalidades)
- [Arquitectura y estructura principal](#arquitectura-y-estructura-principal)
- [Requisitos](#requisitos)
- [Extension requerida](#extension-requerida)
- [Inicio rapido (desarrollo)](#inicio-rapido-desarrollo)
- [Scripts utiles](#scripts-utiles)
- [Pruebas](#pruebas)
- [Resolucion de problemas](#resolucion-de-problemas)

## Vision general

Hemeroteca centraliza el flujo de archivado digital de fuentes web:

- registro de metadatos de una fuente;
- asociacion de etiquetas y oficios;
- almacenamiento del respaldo (WACZ);
- consulta, reproduccion y descarga posterior;
- control de acceso por roles y permisos.

## Tecnologias

- Backend: Laravel 13 (PHP 8.3)
- Frontend: React 19 + TypeScript + Inertia.js
- Build: Vite
- Estilos: Tailwind CSS
- Base de datos: MySQL/MariaDB/PostgreSQL (segun configuracion Laravel)
- Pruebas: Pest

## Funcionalidades

- Registro de fuentes (URL, titulo, descripcion y contenido).
- Carga y gestion de respaldos WACZ.
- Vista y descarga de respaldos.
- Filtros y busqueda por texto, fechas y etiquetas.
- Edicion de metadatos de una fuente.
- Control de permisos por rol.

## Arquitectura y estructura principal

- app/Http/Controllers/HemerotecaController.php: logica principal de hemeroteca.
- routes/web.php: rutas de hemeroteca, respaldo y acciones relacionadas.
- resources/js/pages/hemeroteca.tsx: pantalla principal de consulta y filtros.
- resources/js/components/source-details-modal.tsx: detalle y edicion de fuente.
- database/migrations/2026_03_12_170204_tablas.php: tablas de fuentes, etiquetas y oficios.
- tests/Feature/HemerotecaPageTest.php: pruebas de acceso y flujo principal.

## Requisitos

- PHP 8.3+
- Composer
- Node.js 20+ y npm
- Base de datos configurada en .env

## Extension requerida

Para completar el flujo de captura/archivado desde navegador, este sistema depende de la extension oficial:

- Repositorio: https://github.com/HectorUwO/webarchiver-extension-fisnay

## Inicio rapido (desarrollo)

1. Instala dependencias PHP:

```bash
composer install
```

2. Crea el archivo de entorno:

```bash
cp .env.example .env
```

En Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

3. Genera la key de la app:

```bash
php artisan key:generate
```

4. Configura base de datos en .env y ejecuta migraciones:

```bash
php artisan migrate
```

5. Instala dependencias frontend:

```bash
npm install
```

### Atajo de setup

```bash
composer run setup
```

## Scripts utiles

| Script | Descripcion |
| --- | --- |
| composer run dev | Levanta app en desarrollo (Laravel, cola y Vite). |
| php artisan serve | Levanta solo servidor backend. |
| npm run dev | Levanta solo Vite en desarrollo. |
| npm run build | Build de frontend para produccion. |
| npm run types:check | Verificacion de tipos TypeScript. |
| npm run lint:check | Revision de lint sin aplicar cambios. |
| npm run format:check | Revision de formato sin modificar archivos. |

## Pruebas

Ejecutar suite de pruebas:

```bash
php artisan test
```

Flujo de test del proyecto (incluye validaciones adicionales definidas en Composer):

```bash
composer run test
```

## Resolucion de problemas

### Error de Wayfinder al iniciar Vite

Si aparece un error de generacion de tipos de Wayfinder, limpia caches y regenera:

```bash
php artisan optimize:clear
php artisan wayfinder:generate --with-form
```

### Problemas de dependencias frontend

```bash
rm -rf node_modules
npm install
```

En PowerShell:

```powershell
Remove-Item -Recurse -Force node_modules
npm install
```