-- Agregar columna rol a la tabla usuarios (si no existe)
ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS rol ENUM('admin','usuario') DEFAULT 'usuario' AFTER password;

-- El usuario admin existente debe ser admin
UPDATE usuarios SET rol = 'admin' WHERE email = 'admin@lizfotoestudio.com';

-- Verificar
SELECT id, nombre, email, rol FROM usuarios;
