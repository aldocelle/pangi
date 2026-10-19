-- Esquema completo de la base (39 tablas). Compatible con MariaDB 10.6+ y
-- MySQL 8.0+: collation utf8mb4_unicode_ci, sin sintaxis propia de MariaDB.
--
-- Se carga sobre una base VACÍA. Con datos, mejor cargar el respaldo de la
-- demo (pangi_demo.sql), que ya trae este esquema.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `ajustes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ajustes` (
  `id` int(11) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  `google_account_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `calendario_reservas` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ajustes_google_account_id_fkey` (`google_account_id`),
  CONSTRAINT `ajustes_google_account_id_fkey` FOREIGN KEY (`google_account_id`) REFERENCES `google_accounts` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chatbot_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chatbot_conversations` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `usuario_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `es_activa` tinyint(1) DEFAULT NULL,
  `messages` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`messages`)),
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `estado` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`estado`)),
  PRIMARY KEY (`id`),
  KEY `ix_chatbot_conversations_created_at` (`created_at`),
  KEY `ix_chatbot_conversations_telefono` (`telefono`),
  KEY `chatbot_conversations_usuario_id_fkey` (`usuario_id`),
  CONSTRAINT `chatbot_conversations_usuario_id_fkey` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chatbot_preguntas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chatbot_preguntas` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `texto` varchar(300) NOT NULL,
  `motivo` varchar(60) DEFAULT NULL,
  `respondida` tinyint(1) NOT NULL DEFAULT 0,
  `canal` varchar(20) NOT NULL DEFAULT 'web',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_chatbot_preguntas_created_at` (`created_at`),
  KEY `ix_chatbot_preguntas_respondida` (`respondida`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `citas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `citas` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mascota_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `usuario_dueno_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fecha_hora` datetime(6) NOT NULL,
  `servicio` varchar(100) DEFAULT NULL,
  `estado` varchar(50) DEFAULT NULL,
  `notas_pre_cita` text DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `servicio_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `especialista_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `duracion_min` int(11) DEFAULT 30,
  `respuestas` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT ('{}') CHECK (json_valid(`respuestas`)),
  `google_event_id` varchar(255) DEFAULT NULL,
  `google_calendar_id` varchar(255) DEFAULT NULL,
  `recordatorio_24h_enviado_at` datetime(6) DEFAULT NULL,
  `recordatorio_2h_enviado_at` datetime(6) DEFAULT NULL,
  `recordatorio_15min_enviado_at` datetime(6) DEFAULT NULL,
  `id_legado` varchar(50) DEFAULT NULL,
  `forma_pago` varchar(50) DEFAULT 'transferencia',
  `payment_token` varchar(255) DEFAULT NULL,
  `payment_status` varchar(50) DEFAULT 'pending',
  `monto_abono_clp` int(11) DEFAULT NULL,
  `pago_request_id` varchar(60) DEFAULT NULL,
  `pago_process_url` varchar(500) DEFAULT NULL,
  `pago_referencia` varchar(60) DEFAULT NULL,
  `pago_autorizacion` varchar(60) DEFAULT NULL,
  `pago_actualizado_at` datetime(6) DEFAULT NULL,
  `monto_total_clp` int(11) DEFAULT NULL,
  `pago_modalidad` varchar(10) DEFAULT NULL,
  `pago_modalidad_requerida` varchar(10) DEFAULT NULL,
  `pago_enlace_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_citas_fecha_hora` (`fecha_hora`),
  KEY `ix_citas_id_legado` (`id_legado`),
  KEY `ix_citas_pago_request_id` (`pago_request_id`),
  KEY `citas_especialista_id_fkey` (`especialista_id`),
  KEY `citas_mascota_id_fkey` (`mascota_id`),
  KEY `citas_servicio_id_fkey` (`servicio_id`),
  KEY `citas_usuario_dueno_id_fkey` (`usuario_dueno_id`),
  CONSTRAINT `citas_especialista_id_fkey` FOREIGN KEY (`especialista_id`) REFERENCES `especialistas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `citas_mascota_id_fkey` FOREIGN KEY (`mascota_id`) REFERENCES `mascotas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `citas_servicio_id_fkey` FOREIGN KEY (`servicio_id`) REFERENCES `servicios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `citas_usuario_dueno_id_fkey` FOREIGN KEY (`usuario_dueno_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `disponibilidad_especialistas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `disponibilidad_especialistas` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `especialista_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dia_semana` int(11) NOT NULL,
  `hora_inicio` varchar(5) NOT NULL,
  `hora_fin` varchar(5) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_disponibilidad_especialistas_especialista_id` (`especialista_id`),
  CONSTRAINT `disponibilidad_especialistas_especialista_id_fkey` FOREIGN KEY (`especialista_id`) REFERENCES `especialistas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `documentos_clinicos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentos_clinicos` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ficha_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nota_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `subido_por_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `tipo` varchar(30) NOT NULL,
  `descripcion` varchar(300) DEFAULT NULL,
  `nombre_original` varchar(255) NOT NULL,
  `nombre_archivo` varchar(300) NOT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `tamano_bytes` int(11) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_documentos_clinicos_created_at` (`created_at`),
  KEY `ix_documentos_clinicos_ficha_id` (`ficha_id`),
  KEY `documentos_clinicos_nota_id_fkey` (`nota_id`),
  KEY `documentos_clinicos_subido_por_id_fkey` (`subido_por_id`),
  CONSTRAINT `documentos_clinicos_ficha_id_fkey` FOREIGN KEY (`ficha_id`) REFERENCES `fichas_clinicas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `documentos_clinicos_nota_id_fkey` FOREIGN KEY (`nota_id`) REFERENCES `notas_clinicas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `documentos_clinicos_subido_por_id_fkey` FOREIGN KEY (`subido_por_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `especialistas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `especialistas` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` varchar(200) NOT NULL,
  `especialidad` varchar(150) DEFAULT NULL,
  `emoji` varchar(8) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT NULL,
  `orden` int(11) DEFAULT NULL,
  `slug` varchar(120) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `foto` varchar(300) DEFAULT NULL,
  `zona_horaria` varchar(60) DEFAULT 'America/Santiago',
  `disponibilidad_modo` varchar(20) DEFAULT 'todos',
  `calendarios_conflicto` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT ('[]') CHECK (json_valid(`calendarios_conflicto`)),
  `solo_consulta_whatsapp` tinyint(1) DEFAULT 0,
  `precio_clp` int(11) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `recibe_avisos` tinyint(1) NOT NULL DEFAULT 0,
  `reservable` tinyint(1) NOT NULL DEFAULT 1,
  `publico` tinyint(1) NOT NULL DEFAULT 1,
  `google_calendar_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_especialistas_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facturas_compra`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facturas_compra` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `proveedor_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `numero_factura` varchar(100) DEFAULT NULL,
  `fecha_recibo` date NOT NULL,
  `fecha_emision` date DEFAULT NULL,
  `fecha_vencimiento_pago` date DEFAULT NULL,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `estado_pago` varchar(20) NOT NULL DEFAULT 'no_pagada',
  `fecha_pago` date DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `creado_por_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `creado_por_nombre` varchar(255) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_facturas_compra_estado_pago` (`estado_pago`),
  KEY `ix_facturas_compra_fecha_recibo` (`fecha_recibo`),
  KEY `ix_facturas_compra_proveedor_id` (`proveedor_id`),
  KEY `facturas_compra_creado_por_id_fkey` (`creado_por_id`),
  CONSTRAINT `facturas_compra_creado_por_id_fkey` FOREIGN KEY (`creado_por_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `facturas_compra_proveedor_id_fkey` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fichas_clinicas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fichas_clinicas` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mascota_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `antecedentes` text DEFAULT NULL,
  `vacunas` text DEFAULT NULL,
  `condiciones_cronicas` text DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `caracter` varchar(20) DEFAULT NULL,
  `vif` varchar(20) DEFAULT NULL,
  `vilef` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fichas_clinicas_mascota_id_key` (`mascota_id`),
  CONSTRAINT `fichas_clinicas_mascota_id_fkey` FOREIGN KEY (`mascota_id`) REFERENCES `mascotas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `form_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `form_fields` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `etiqueta` varchar(200) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `opciones` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`opciones`)),
  `requerido` tinyint(1) DEFAULT NULL,
  `oculto` tinyint(1) DEFAULT NULL,
  `orden` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `google_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `google_accounts` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `email` varchar(255) NOT NULL,
  `access_token_enc` varchar(4096) DEFAULT NULL,
  `refresh_token_enc` varchar(2048) DEFAULT NULL,
  `token_expiry` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `google_accounts_email_key` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `horario_bloques`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `horario_bloques` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `horario_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dia_semana` int(11) NOT NULL,
  `hora_inicio` varchar(5) NOT NULL,
  `hora_fin` varchar(5) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_horario_bloques_horario_id` (`horario_id`),
  CONSTRAINT `horario_bloques_horario_id_fkey` FOREIGN KEY (`horario_id`) REFERENCES `horarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `horarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `horarios` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `especialista_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` varchar(100) DEFAULT NULL,
  `orden` int(11) DEFAULT NULL,
  `todos_servicios` tinyint(1) DEFAULT NULL,
  `servicio_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`servicio_ids`)),
  `valido_desde` date DEFAULT NULL,
  `valido_hasta` date DEFAULT NULL,
  `semana_paridad` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_horarios_especialista_id` (`especialista_id`),
  CONSTRAINT `horarios_especialista_id_fkey` FOREIGN KEY (`especialista_id`) REFERENCES `especialistas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `items_inventario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `items_inventario` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `descripcion` varchar(500) DEFAULT NULL,
  `tipo` varchar(50) NOT NULL,
  `unidad` varchar(30) DEFAULT NULL,
  `precio_costo` decimal(12,2) DEFAULT NULL,
  `precio_venta` decimal(12,2) DEFAULT NULL,
  `stock_actual` int(11) DEFAULT NULL,
  `stock_minimo` int(11) DEFAULT NULL,
  `lote` varchar(100) DEFAULT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `activo` tinyint(1) DEFAULT NULL,
  `id_legado` varchar(50) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `proveedor` varchar(255) DEFAULT NULL,
  `bodega` varchar(30) NOT NULL DEFAULT 'Principal',
  `uso_interno` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_items_inventario_id_legado` (`id_legado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lineas_factura_compra`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lineas_factura_compra` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `factura_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `item_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `item_nombre_libre` varchar(255) DEFAULT NULL,
  `cantidad` int(11) NOT NULL,
  `costo_unitario` decimal(12,2) NOT NULL DEFAULT 0.00,
  `lote` varchar(100) DEFAULT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_lineas_factura_compra_factura_id` (`factura_id`),
  KEY `ix_lineas_factura_compra_item_id` (`item_id`),
  CONSTRAINT `lineas_factura_compra_factura_id_fkey` FOREIGN KEY (`factura_id`) REFERENCES `facturas_compra` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `lineas_factura_compra_item_id_fkey` FOREIGN KEY (`item_id`) REFERENCES `items_inventario` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `logs_actividad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs_actividad` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `usuario_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `usuario_nombre` varchar(255) NOT NULL,
  `usuario_rol` varchar(50) DEFAULT NULL,
  `accion` varchar(100) NOT NULL,
  `area` varchar(50) NOT NULL,
  `entidad` varchar(100) DEFAULT NULL,
  `entidad_id` varchar(100) DEFAULT NULL,
  `detalle` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`detalle`)),
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_logs_actividad_accion` (`accion`),
  KEY `ix_logs_actividad_area` (`area`),
  KEY `ix_logs_actividad_created_at` (`created_at`),
  KEY `ix_logs_actividad_usuario_id` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marcajes_asistencia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `marcajes_asistencia` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `usuario_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tipo` varchar(10) NOT NULL,
  `momento` datetime(6) NOT NULL,
  `registrado_por_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `nota` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_marcajes_usuario_momento` (`usuario_id`,`momento`),
  KEY `ix_marcajes_momento` (`momento`),
  KEY `fk_marcajes_registrador` (`registrado_por_id`),
  CONSTRAINT `fk_marcajes_registrador` FOREIGN KEY (`registrado_por_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_marcajes_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mascotas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mascotas` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `usuario_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `especie` varchar(50) DEFAULT NULL,
  `raza` varchar(100) DEFAULT NULL,
  `sexo` varchar(10) DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `peso_kg` decimal(5,2) DEFAULT NULL,
  `alergias` varchar(500) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `microchip_codigo` varchar(50) DEFAULT NULL,
  `foto_url` varchar(500) DEFAULT NULL,
  `esterilizado` tinyint(1) DEFAULT NULL,
  `estado` varchar(20) DEFAULT 'Vivo',
  `fecha_fallecido` date DEFAULT NULL,
  `id_legado` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_mascotas_id_legado` (`id_legado`),
  KEY `ix_mascotas_microchip_codigo` (`microchip_codigo`),
  KEY `mascotas_usuario_id_fkey` (`usuario_id`),
  CONSTRAINT `mascotas_usuario_id_fkey` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mensajes_contacto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mensajes_contacto` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` varchar(200) NOT NULL,
  `email` varchar(255) NOT NULL,
  `asunto` varchar(300) DEFAULT NULL,
  `mensaje` text NOT NULL,
  `leido` tinyint(1) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `leido_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_mensajes_contacto_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `movimientos_caja`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `movimientos_caja` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sesion_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `usuario_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `tipo` varchar(20) NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `comentario` varchar(300) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_movimientos_caja_created_at` (`created_at`),
  KEY `ix_movimientos_caja_sesion_id` (`sesion_id`),
  KEY `movimientos_caja_usuario_id_fkey` (`usuario_id`),
  CONSTRAINT `movimientos_caja_sesion_id_fkey` FOREIGN KEY (`sesion_id`) REFERENCES `sesiones_caja` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `movimientos_caja_usuario_id_fkey` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notas_clinicas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notas_clinicas` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ficha_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `veterinario_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `cita_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `examen` text DEFAULT NULL,
  `diagnostico` text DEFAULT NULL,
  `tratamiento` text DEFAULT NULL,
  `indicaciones` text DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `especialista_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `id_legado` varchar(50) DEFAULT NULL,
  `proximo_control` date DEFAULT NULL,
  `sanitario` text DEFAULT NULL,
  `conciencia` varchar(30) DEFAULT NULL,
  `condicion_corporal` varchar(20) DEFAULT NULL,
  `mucosas` varchar(30) DEFAULT NULL,
  `hidratacion` varchar(30) DEFAULT NULL,
  `frecuencia_cardiaca` int(11) DEFAULT NULL,
  `frecuencia_respiratoria` int(11) DEFAULT NULL,
  `temperatura` decimal(4,1) DEFAULT NULL,
  `dolor_escala` varchar(20) DEFAULT NULL,
  `dolor_origen` varchar(150) DEFAULT NULL,
  `periodontal` varchar(20) DEFAULT NULL,
  `prediagnosticos` text DEFAULT NULL,
  `examenes_complementarios` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_notas_clinicas_created_at` (`created_at`),
  KEY `ix_notas_clinicas_ficha_id` (`ficha_id`),
  KEY `ix_notas_clinicas_id_legado` (`id_legado`),
  KEY `notas_clinicas_cita_id_fkey` (`cita_id`),
  KEY `notas_clinicas_especialista_id_fkey` (`especialista_id`),
  KEY `notas_clinicas_veterinario_id_fkey` (`veterinario_id`),
  CONSTRAINT `notas_clinicas_cita_id_fkey` FOREIGN KEY (`cita_id`) REFERENCES `citas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `notas_clinicas_especialista_id_fkey` FOREIGN KEY (`especialista_id`) REFERENCES `especialistas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `notas_clinicas_ficha_id_fkey` FOREIGN KEY (`ficha_id`) REFERENCES `fichas_clinicas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `notas_clinicas_veterinario_id_fkey` FOREIGN KEY (`veterinario_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plantillas_recordatorio`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `plantillas_recordatorio` (
  `id` char(36) NOT NULL,
  `titulo` varchar(120) NOT NULL,
  `mensaje` text DEFAULT NULL,
  `dias_repeticion` int(11) DEFAULT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `protocolo_aplicaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `protocolo_aplicaciones` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `protocolo_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mascota_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fecha_aplicacion` date NOT NULL,
  `proxima_fecha` date DEFAULT NULL,
  `nota_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `registrado_por_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_protocolo_aplicaciones_mascota_id` (`mascota_id`),
  KEY `ix_protocolo_aplicaciones_protocolo_id` (`protocolo_id`),
  KEY `protocolo_aplicaciones_nota_id_fkey` (`nota_id`),
  KEY `protocolo_aplicaciones_registrado_por_id_fkey` (`registrado_por_id`),
  CONSTRAINT `protocolo_aplicaciones_mascota_id_fkey` FOREIGN KEY (`mascota_id`) REFERENCES `mascotas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `protocolo_aplicaciones_nota_id_fkey` FOREIGN KEY (`nota_id`) REFERENCES `notas_clinicas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `protocolo_aplicaciones_protocolo_id_fkey` FOREIGN KEY (`protocolo_id`) REFERENCES `protocolos_preventivos` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `protocolo_aplicaciones_registrado_por_id_fkey` FOREIGN KEY (`registrado_por_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `protocolos_preventivos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `protocolos_preventivos` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `categoria` varchar(80) DEFAULT NULL,
  `especie` varchar(50) DEFAULT NULL,
  `frecuencia_meses` int(11) NOT NULL,
  `edad_inicio_meses` int(11) DEFAULT NULL,
  `edad_fin_meses` int(11) DEFAULT NULL,
  `inicial` tinyint(1) NOT NULL DEFAULT 0,
  `descripcion` text DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `proveedores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `proveedores` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `contacto_nombre` varchar(255) DEFAULT NULL,
  `telefono` varchar(60) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proveedores_nombre_key` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `recordatorios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recordatorios` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mascota_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `usuario_dueno_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `titulo` varchar(255) NOT NULL,
  `categoria` varchar(80) DEFAULT NULL,
  `mensaje` text DEFAULT NULL,
  `fecha_programada` date NOT NULL,
  `dias_repeticion` int(11) DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
  `enviado_at` datetime(6) DEFAULT NULL,
  `canal` varchar(20) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `creado_por_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `id_legado` varchar(50) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT current_timestamp(6),
  `updated_at` datetime(6) DEFAULT current_timestamp(6),
  `nota_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `protocolo_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_recordatorios_legado` (`id_legado`),
  KEY `ix_recordatorios_dueno` (`usuario_dueno_id`),
  KEY `ix_recordatorios_estado` (`estado`),
  KEY `ix_recordatorios_fecha` (`fecha_programada`),
  KEY `ix_recordatorios_mascota` (`mascota_id`),
  KEY `ix_recordatorios_nota_id` (`nota_id`),
  KEY `ix_recordatorios_protocolo_id` (`protocolo_id`),
  KEY `recordatorios_creado_por_id_fkey` (`creado_por_id`),
  CONSTRAINT `recordatorios_creado_por_id_fkey` FOREIGN KEY (`creado_por_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `recordatorios_mascota_id_fkey` FOREIGN KEY (`mascota_id`) REFERENCES `mascotas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `recordatorios_nota_id_fkey` FOREIGN KEY (`nota_id`) REFERENCES `notas_clinicas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `recordatorios_protocolo_id_fkey` FOREIGN KEY (`protocolo_id`) REFERENCES `protocolos_preventivos` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `recordatorios_usuario_dueno_id_fkey` FOREIGN KEY (`usuario_dueno_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `servicio_especialista`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `servicio_especialista` (
  `servicio_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `especialista_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`servicio_id`,`especialista_id`),
  KEY `servicio_especialista_especialista_id_fkey` (`especialista_id`),
  CONSTRAINT `servicio_especialista_especialista_id_fkey` FOREIGN KEY (`especialista_id`) REFERENCES `especialistas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `servicio_especialista_servicio_id_fkey` FOREIGN KEY (`servicio_id`) REFERENCES `servicios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `servicios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `servicios` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `descripcion` varchar(500) DEFAULT NULL,
  `emoji` varchar(8) DEFAULT NULL,
  `imagen` varchar(160) DEFAULT NULL,
  `duracion_min` int(11) NOT NULL,
  `precio_clp` int(11) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT NULL,
  `orden` int(11) DEFAULT NULL,
  `precio_detalle` varchar(300) DEFAULT NULL,
  `duracion_visible_min` int(11) DEFAULT NULL,
  `precio_sabado_clp` int(11) DEFAULT NULL,
  `modalidad_pago` varchar(10) NOT NULL DEFAULT 'abono',
  `tarifa_por_especialista` tinyint(1) NOT NULL DEFAULT 0,
  `abono_fijo_clp` int(11) DEFAULT NULL,
  `requiere_orden_examen` tinyint(1) NOT NULL DEFAULT 0,
  `coordinar_whatsapp` tinyint(1) NOT NULL DEFAULT 0,
  `grupo` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sesiones_caja`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sesiones_caja` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `usuario_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fecha` date NOT NULL,
  `abierta_at` datetime(6) NOT NULL,
  `cerrada_at` datetime(6) DEFAULT NULL,
  `monto_inicial` decimal(12,2) NOT NULL,
  `monto_declarado` decimal(12,2) DEFAULT NULL,
  `monto_esperado` decimal(12,2) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_sesiones_caja_fecha` (`fecha`),
  KEY `ix_sesiones_caja_usuario_id` (`usuario_id`),
  CONSTRAINT `sesiones_caja_usuario_id_fkey` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `solicitudes_especialista`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `solicitudes_especialista` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `especialista_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `numero` int(11) NOT NULL,
  `tutor_telefono` varchar(20) NOT NULL,
  `tutor_nombre` varchar(80) DEFAULT NULL,
  `mascota` varchar(60) DEFAULT NULL,
  `servicio` varchar(120) DEFAULT NULL,
  `motivo` varchar(400) DEFAULT NULL,
  `estado` varchar(30) NOT NULL,
  `mensajes` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_solicitudes_especialista_esp` (`especialista_id`,`estado`),
  KEY `ix_solicitudes_especialista_tutor` (`tutor_telefono`,`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `solicitudes_reset`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `solicitudes_reset` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `email` varchar(255) NOT NULL,
  `resuelta` tinyint(1) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `resuelta_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_solicitudes_reset_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `solicitudes_titular`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `solicitudes_titular` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `usuario_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `detalle` text DEFAULT NULL,
  `estado` varchar(20) NOT NULL,
  `respuesta` text DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `resuelta_at` datetime(6) DEFAULT NULL,
  `resuelta_por_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `resuelta_por_nombre` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_solicitudes_titular_created_at` (`created_at`),
  KEY `ix_solicitudes_titular_email` (`email`),
  KEY `ix_solicitudes_titular_estado` (`estado`),
  KEY `ix_solicitudes_titular_usuario_id` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tareas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tareas` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `prioridad` varchar(10) NOT NULL DEFAULT 'media',
  `area` varchar(60) DEFAULT NULL,
  `asignado_a_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `completada` tinyint(1) NOT NULL DEFAULT 0,
  `en_curso` tinyint(1) NOT NULL DEFAULT 0,
  `completada_at` datetime(6) DEFAULT NULL,
  `creado_por_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tareas_asignado_a_id_fkey` (`asignado_a_id`),
  KEY `tareas_creado_por_id_fkey` (`creado_por_id`),
  CONSTRAINT `tareas_asignado_a_id_fkey` FOREIGN KEY (`asignado_a_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `tareas_creado_por_id_fkey` FOREIGN KEY (`creado_por_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tareas_corridas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tareas_corridas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tarea` varchar(60) NOT NULL,
  `inicio` datetime(3) NOT NULL,
  `fin` datetime(3) NOT NULL,
  `duracion_ms` int(10) unsigned NOT NULL,
  `resultado` varchar(10) NOT NULL,
  `resumen` varchar(500) DEFAULT NULL,
  `origen` varchar(10) NOT NULL DEFAULT 'cron',
  `usuario_id` varchar(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_tareas_corridas_tarea_inicio` (`tarea`,`inicio`),
  KEY `ix_tareas_corridas_inicio` (`inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tareas_recurrentes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tareas_recurrentes` (
  `id` char(36) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `frecuencia` varchar(10) NOT NULL,
  `area` varchar(60) DEFAULT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `creado_por_id` char(36) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transacciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transacciones` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `propietario_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `especialista_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `fecha` datetime(6) NOT NULL,
  `documento_id` varchar(500) DEFAULT NULL,
  `pago_efectivo` decimal(12,2) DEFAULT NULL,
  `pago_debito` decimal(12,2) DEFAULT NULL,
  `pago_credito` decimal(12,2) DEFAULT NULL,
  `pago_transferencia` decimal(12,2) DEFAULT NULL,
  `pago_otro` decimal(12,2) DEFAULT NULL,
  `total_pendiente` decimal(12,2) DEFAULT NULL,
  `id_legado` varchar(50) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `numero` int(11) NOT NULL AUTO_INCREMENT,
  `creado_por_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `creado_por_nombre` varchar(255) DEFAULT NULL,
  `estado_venta` varchar(20) NOT NULL DEFAULT 'confirmada',
  `cuenta_en_caja` tinyint(1) NOT NULL DEFAULT 1,
  `anulada_at` datetime(6) DEFAULT NULL,
  `anulada_por_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `anulada_por_nombre` varchar(255) DEFAULT NULL,
  `motivo_anulacion` varchar(500) DEFAULT NULL,
  `puntos_ganados` int(11) NOT NULL DEFAULT 0,
  `puntos_canjeados` int(11) NOT NULL DEFAULT 0,
  `monto_canje` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_transacciones_numero` (`numero`),
  KEY `ix_transacciones_cuenta_en_caja` (`cuenta_en_caja`),
  KEY `ix_transacciones_estado_venta` (`estado_venta`),
  KEY `ix_transacciones_fecha` (`fecha`),
  KEY `ix_transacciones_id_legado` (`id_legado`),
  KEY `ix_transacciones_propietario_fecha` (`propietario_id`,`fecha`),
  KEY `ix_transacciones_propietario_id` (`propietario_id`),
  KEY `transacciones_anulada_por_id_fkey` (`anulada_por_id`),
  KEY `transacciones_especialista_id_fkey` (`especialista_id`),
  CONSTRAINT `transacciones_anulada_por_id_fkey` FOREIGN KEY (`anulada_por_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `transacciones_especialista_id_fkey` FOREIGN KEY (`especialista_id`) REFERENCES `especialistas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `transacciones_propietario_id_fkey` FOREIGN KEY (`propietario_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=330 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `uso_asistente`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `uso_asistente` (
  `fecha` date NOT NULL,
  `llamadas` int(11) NOT NULL DEFAULT 0,
  `tokens_entrada` int(11) NOT NULL DEFAULT 0,
  `tokens_salida` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `nombre` varchar(255) NOT NULL,
  `telefono` varchar(60) DEFAULT NULL,
  `rol` varchar(50) DEFAULT NULL,
  `es_activo` tinyint(1) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `rut` varchar(20) DEFAULT NULL,
  `direccion` varchar(300) DEFAULT NULL,
  `ciudad` varchar(120) DEFAULT NULL,
  `region` varchar(120) DEFAULT NULL,
  `puntos_fidelizacion` int(11) DEFAULT 0,
  `id_legado` varchar(50) DEFAULT NULL,
  `email_verificado` tinyint(1) NOT NULL DEFAULT 0,
  `password_hash_pendiente` varchar(255) DEFAULT NULL,
  `token_confirmacion` varchar(64) DEFAULT NULL,
  `token_confirmacion_expira` datetime(6) DEFAULT NULL,
  `permisos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permisos`)),
  `terms_accepted` tinyint(1) NOT NULL DEFAULT 0,
  `terms_accepted_at` datetime(6) DEFAULT NULL,
  `terms_version` varchar(20) DEFAULT NULL,
  `privacy_accepted` tinyint(1) NOT NULL DEFAULT 0,
  `privacy_accepted_at` datetime(6) DEFAULT NULL,
  `privacy_version` varchar(20) DEFAULT NULL,
  `marketing_consent` tinyint(1) NOT NULL DEFAULT 0,
  `marketing_consent_at` datetime(6) DEFAULT NULL,
  `token_reset` varchar(64) DEFAULT NULL,
  `token_reset_expira` datetime(6) DEFAULT NULL,
  `cargo` varchar(100) DEFAULT NULL,
  `marketing_consent_retirado_at` datetime(6) DEFAULT NULL,
  `sesion_version` int(11) NOT NULL DEFAULT 0,
  `foto` varchar(160) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_usuarios_email` (`email`),
  UNIQUE KEY `ix_usuarios_google_id` (`google_id`),
  UNIQUE KEY `ix_usuarios_telefono_unico` (`telefono`),
  KEY `ix_usuarios_id_legado` (`id_legado`),
  KEY `ix_usuarios_rut` (`rut`),
  KEY `ix_usuarios_token_confirmacion` (`token_confirmacion`),
  KEY `ix_usuarios_token_reset` (`token_reset`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ventas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ventas` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `transaccion_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `item_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `propietario_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `mascota_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `especialista_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `fecha` datetime(6) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `tipo` varchar(50) DEFAULT NULL,
  `cantidad` int(11) DEFAULT NULL,
  `precio_costo` decimal(12,2) DEFAULT NULL,
  `precio_venta` decimal(12,2) DEFAULT NULL,
  `descuento` decimal(12,2) DEFAULT NULL,
  `id_legado` varchar(50) DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `servicio_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `cantidad_devuelta` int(11) NOT NULL DEFAULT 0,
  `devuelta_at` datetime(6) DEFAULT NULL,
  `devuelta_por_nombre` varchar(255) DEFAULT NULL,
  `motivo_devolucion` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ventas_fecha` (`fecha`),
  KEY `ix_ventas_id_legado` (`id_legado`),
  KEY `ix_ventas_item_id` (`item_id`),
  KEY `ix_ventas_mascota_id` (`mascota_id`),
  KEY `ix_ventas_propietario_id` (`propietario_id`),
  KEY `ix_ventas_servicio_id` (`servicio_id`),
  KEY `ix_ventas_transaccion_id` (`transaccion_id`),
  KEY `ventas_especialista_id_fkey` (`especialista_id`),
  CONSTRAINT `ventas_especialista_id_fkey` FOREIGN KEY (`especialista_id`) REFERENCES `especialistas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `ventas_item_id_fkey` FOREIGN KEY (`item_id`) REFERENCES `items_inventario` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `ventas_mascota_id_fkey` FOREIGN KEY (`mascota_id`) REFERENCES `mascotas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `ventas_propietario_id_fkey` FOREIGN KEY (`propietario_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `ventas_servicio_id_fkey` FOREIGN KEY (`servicio_id`) REFERENCES `servicios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `ventas_transaccion_id_fkey` FOREIGN KEY (`transaccion_id`) REFERENCES `transacciones` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

