# Proyecto Hemeroteca

**Dirección General de Análisis de Información e Inteligencia Criminal**  
Fiscalía del Estado de Nayarit

---

## Alcance

El proyecto contempla la implementación de un módulo institucional de archivado digital automatizado orientado a la recolección, preservación, almacenamiento estructurado y consulta de información proveniente de fuentes abiertas haciendo uso de técnicas de web scraping.

---

## Objetivos

### Objetivo General

Desarrollar un módulo automatizado para la captura, almacenamiento íntegro y consulta estructurada de contenido proveniente de fuentes abiertas, con el propósito de preservar evidencia digital ante su modificación o eliminación, fortaleciendo los procesos de análisis de información de la Dirección General de Análisis, Información e Inteligencia Criminal de la Fiscalía del Estado de Nayarit.

### Objetivos Específicos

- Definir e implementar un esquema de base de datos.
- Diseñar un sistema de registro.
- Diseñar un sistema de administración de fuentes.
- Incorporar una función de búsqueda.

---

## Justificación

El contenido publicado en fuentes abiertas puede ser modificado o eliminado en cualquier momento, lo que representa un problema para la preservación de información relevante en los procesos de análisis e inteligencia criminal. Actualmente, la DGAIIC no cuenta con un mecanismo que garantice la captura y resguardo íntegro de dicho contenido.

El desarrollo de este módulo busca resolver esa problemática dotando a la Dirección de una herramienta que preserve el contenido digital, reduzca los tiempos de búsqueda y recuperación de información, y permita identificar relaciones entre contenidos archivados de distintas fuentes. Todo esto con el propósito de fortalecer las capacidades institucionales de análisis e inteligencia de la Fiscalía del Estado de Nayarit.

---

## Análisis

### Descripción General del Sistema

El sistema es un sistema web institucional de respaldo digital que permite a los analistas de la DGAIIC capturar, preservar y consultar contenido proveniente de fuentes abiertas para prevenir que dicho contenido sea modificado o eliminado.

### Requisitos Funcionales

- El sistema debe permitir registrar fuentes abiertas mediante URL, con nombre, descripción y fecha de captura.
- Incluir un sistema de etiquetado para una mejor identificación de las fuentes.
- El sistema ejecutará una captura del sitio web haciendo uso de web scraping.
- El sistema debe almacenar el contenido íntegro (HTML, texto plano, imágenes).
- El sistema debe ofrecer búsqueda de texto completo sobre el contenido archivado.
- El sistema debe permitir filtrar resultados por fuente, rango de fechas, tipo y etiquetas.
- El sistema debe gestionar usuarios con roles diferenciados (administrador y analista).
- Agregar tablas para visualización de las fuentes más relevantes.

### Historias de Usuario

#### Desarrollo

- Yo como desarrollador quiero diseñar la base de datos.
- Yo como desarrollador quiero diseñar la interfaz de usuario para que sea más interactiva y fácil de usar.
- Yo como desarrollador quiero diseñar la arquitectura lógica del sistema.
- Yo como desarrollador quiero configurar el proyecto en Laravel.
- Yo como desarrollador quiero crear seeds con datos de prueba para todas las tablas.
- Yo como desarrollador quiero desarrollar el CRUD completo de fuentes.
- Yo como analista, quiero registrar una URL como fuente para que el sistema la guarde automáticamente y preserve su contenido antes de que sea modificado o eliminado.
- Yo como analista quiero filtrar los resultados de la búsqueda para poder acortar mi búsqueda.
- Yo como analista, quiero buscar fácilmente entre las fuentes para ahorrar tiempo.
- Yo como analista, quiero exportar datos automáticamente de las tablas para que sea más fácil la interacción.
- Yo como analista quiero implementar el sistema de filtros.
- Yo como desarrollador quiero hacer pruebas.
- Yo como desarrollador quiero implementar manejo global de errores.
---


## Cronograma

![Cronograma](Imagenes/cronograma.png)

## Diseño

### Stack de Tecnologías

| Componente | Tecnología |
|-----------|-----------|
| Backend | Laravel |
| Frontend | React |
| CSS Framework | Tailwind CSS |
| UI Components | Shadcn |
| Web Scraping | Playwright |
| Base de Datos | MySQL |

### Descripción del Diseño

El diseño de esta plataforma será sencillo a la interacción para su uso, ya que permite guardar mediante la URL de las páginas y realizar el guardado de ellas. Posteriormente a realizar el guardado, se podrá buscar por fecha, por etiquetado para su fácil búsqueda de alguna URL guardada y así tener un mejor control de la información.

### Página Principal

![PaginaPrincial](Imagenes/1.png)

### Registro de Fuente

![RegistrodeFuente](Imagenes/2.png)
