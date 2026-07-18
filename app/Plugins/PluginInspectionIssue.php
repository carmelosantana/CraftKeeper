<?php

namespace App\Plugins;

/**
 * Every way App\Plugins\JarInspector::inspect() can fail to produce full
 * metadata for a JAR — always surfaced as a PluginInspectionDiagnostic on
 * the returned InspectedPlugin, NEVER as a thrown exception. See
 * JarInspector's class docblock for the archive-parsing defenses each of
 * the hostile-input cases below corresponds to.
 */
enum PluginInspectionIssue: string
{
    /** Neither paper-plugin.yml nor plugin.yml is present in the archive. */
    case NoMetadata = 'no_metadata';

    /**
     * No Paper/Bukkit metadata was found, but a velocity-plugin.json or
     * bungee.yml descriptor was — this archive is a proxy plugin for a
     * different platform entirely, not a Paper server plugin.
     */
    case ForeignPlatform = 'foreign_platform';

    /** The metadata entry was found and read, but is not valid YAML. */
    case MalformedYaml = 'malformed_yaml';

    /**
     * The metadata entry parsed as valid YAML but its shape is unusable
     * (the document root isn't a mapping, or it has no usable "name").
     */
    case InvalidMetadataStructure = 'invalid_metadata_structure';

    /**
     * Refused before or during decompression: either the entry's
     * declared uncompressed size, or the number of bytes actually read
     * while streaming it, exceeded JarInspector::MAX_METADATA_BYTES.
     */
    case MetadataTooLarge = 'metadata_too_large';

    /** The archive has more entries than JarInspector::MAX_ENTRY_COUNT. */
    case TooManyEntries = 'too_many_entries';

    /** ZipArchive could not open the file, or could not read a stat/stream it needed. */
    case UnreadableArchive = 'unreadable_archive';
}
