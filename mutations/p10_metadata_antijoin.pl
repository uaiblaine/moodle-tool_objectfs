s|\n\s+AND NOT EXISTS \(SELECT 1 FROM \{files\} f WHERE f\.contenthash = \{tool_objectfs_objects\}\.contenthash\)'|'|;
