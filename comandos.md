# Comandos Básicos de Git

## Configuración Inicial

```bash
# Configurar nombre de usuario
git config --global user.name "Tu Nombre"

# Configurar correo electrónico
git config --global user.email "tu@email.com"

# Ver configuración actual
git config --list
```

## Inicializar y Clonar Repositorios

```bash
# Inicializar un nuevo repositorio
git init

# Clonar un repositorio existente
git clone <url-del-repositorio>
```

## Operaciones Básicas

```bash

# Agregar archivos al staging area
git add <archivo>
git add .  # Agregar todos los archivos

# Hacer un commit
git commit -m "Mensaje del commit"

# Agregar y hacer commit en un solo comando
git commit -am "Mensaje del commit"
```

## Repositorios Remotos

```bash
# Ver repositorios remotos
git remote -v

# Agregar un repositorio remoto
git remote add origin <url-del-repositorio>

# Subir cambios al repositorio remoto
git push origin <nombre-rama>

# Descargar cambios del repositorio remoto
git pull origin <nombre-rama>

# Traer cambios sin fusionar
git fetch
```

## Manejo de Conflictos

```bash
# Ver el estado de los archivos con conflictos
git status

# Ver qué archivos tienen conflictos
git diff --name-only --diff-filter=U

# Ver los conflictos en un archivo específico
git diff <archivo>

# Aceptar la versión actual (nuestra)
git checkout --ours <archivo>

# Aceptar la versión entrante (de ellos)
git checkout --theirs <archivo>

# Después de resolver manualmente los conflictos
git add <archivo>

# Continuar con el merge después de resolver conflictos
git commit

# Abortar el merge y volver al estado anterior
git merge --abort

# Abortar un rebase
git rebase --abort

# Continuar un rebase después de resolver conflictos
git rebase --continue

# Usar una herramienta de merge visual
git mergetool
```


