-- Bloqueos de la agenda (19-oct-2026): horas en que un especialista, o toda la
-- clínica, no atiende (trámites, vacaciones, capacitación). Se descuentan de las
-- horas que ofrece el portal de reservas y se ven sombreadas en la Agenda.
--
-- Para una base que ya está en uso: en phpMyAdmin, entrar a la base → SQL →
-- pegar esto → Continuar. No toca ninguna tabla existente y se puede correr dos
-- veces sin error. Una base nueva no lo necesita: ya viene en esquema.sql.
CREATE TABLE IF NOT EXISTS `bloqueos_agenda` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `especialista_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `inicio` datetime NOT NULL,
  `fin` datetime NOT NULL,
  `motivo` varchar(150) NOT NULL,
  `creado_por_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(6) DEFAULT NULL,
  `anulado_at` datetime(6) DEFAULT NULL,
  `anulado_por_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_bloqueos_agenda_rango` (`inicio`,`fin`),
  KEY `ix_bloqueos_agenda_especialista_id` (`especialista_id`),
  CONSTRAINT `bloqueos_agenda_especialista_id_fkey` FOREIGN KEY (`especialista_id`) REFERENCES `especialistas` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
