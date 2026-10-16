s{try \{\n\s*(\$probe = \(\$client \?\? new client\(\$config\)\)->run_probe\(\);)\n\s*\} catch \(\\Throwable \$e\) \{\n.*?\n\s*\}}{$1}s;
