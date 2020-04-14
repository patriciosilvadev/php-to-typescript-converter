<?php

namespace Snakedove\PHPToTypescriptConverter\Service;

class InterfaceCreator {
    const MATCH_CLASS_NAME = '/\n[a-z ]*?class ([a-zA-Z0-9-_]+)/';
    const MATCH_EXTENDS = '/class [a-zA-Z0-9-_]+ extends ([a-zA-Z0-9-_, ]+)/';
    const MATCH_PROPERTIES = '/\n\t? *[a-z]+ ([^ ]+ [\$a-zA-Z0-9-_]+)( =[^;]+)?;/';
    const MATCH_FUNCTIONS = '/ function ((get|is|has)[\$a-zA-Z0-9-_]+)\(/';
    const CONVERT_TYPES = [
        '/int/' => 'number',
        '/float/' => 'number',
        '/^\\\.+/' => 'any',
        '/array/' => 'any[]',
        '/mixed/' => 'any',
        '/bool/' => 'boolean'
    ];

    private string $inputFile;
    private string $outputFile;
    private string $nameSuffix;

    public function __construct($inputFile, $outputFile, $nameSuffix = '')
    {
        $this->inputFile = $inputFile;
        $this->outputFile = $outputFile;
        $this->nameSuffix = $nameSuffix;
    }

    public function run(): string {
        $run = $this->writeInterface($this->parseFile($this->inputFile));
        if ($run) {
            return $this->outputFile . $this->nameSuffix . ' created';
        }

        return 'OOPS: could not create ' . $this->outputFile;
    }

    private function getMatch($pattern, $subject): string {
        $matches = null;
        $matched = preg_match($pattern, $subject, $matches);

        if ($matched && !empty($matches) && isset($matches[1])) {
            return $matches[1];
        }

        return '';
    }

    private function getMatches($pattern, $subject): array {
        $matches = null;
        $matched = preg_match_all($pattern, $subject, $matches);

        if ($matched && !empty($matches) && isset($matches[1])) {
            return $matches[1];
        }

        return [];
    }

    private function convertType(string $type): string {
        foreach (self::CONVERT_TYPES as $from => $to) {
            if (preg_match($from, $type)) {
                return preg_replace($from, $to, $type);
            }
        }

        return $type;
    }

    private function parseFile(string $filePath): ParsedFile {
        $fileContent = file_get_contents($filePath);
        $parsedFile = new ParsedFile();

        $classNameMatch = $this->getMatch(self::MATCH_CLASS_NAME, $fileContent);
        
        if (!empty($classNameMatch)) {
            $parsedFile->setClassName($classNameMatch . $this->nameSuffix);
        }

        $extendsMatch = $this->getMatch(self::MATCH_EXTENDS, $fileContent);
        
        if (!empty($extendsMatch)) {
            $extendsMatch = str_replace(' ', '', $extendsMatch);
            $extends = explode(',', $extendsMatch);
            
            foreach ($extends as $index => $ext) {
                $extends[$index] = $ext . $this->nameSuffix;
            }
            
            $parsedFile->setExtends(implode(', ', $extends));
        }

        $propsMatch = $this->getMatches(self::MATCH_PROPERTIES, $fileContent);
        $funcsMatch = $this->getMatches(self::MATCH_FUNCTIONS, $fileContent);
        $classProps = [];

        foreach ($propsMatch as $prop) {
            $propParts = explode(' ', $prop);
            $name = str_replace('$', '', $propParts[1]);
            $type = preg_replace('/\?/', '', $propParts[0]);
            $type = $this->convertType($type);
            $newProp = $name  . ': ' . $type . ';';

            if (in_array('get' . ucfirst($name), $funcsMatch) ||
                in_array('has' . ucfirst($name), $funcsMatch) ||
                in_array('is' . ucfirst($name), $funcsMatch)) {
                array_push($classProps, $newProp);
            }
            
            // set collection property for collections
            if (in_array('toArray', $funcsMatch)) {
                array_push($classProps, 'collection: any;');
            }
        }

        if (!empty($classProps)) {
            $parsedFile->setProperties($classProps);
        }

        return $parsedFile;
    }

    private function writeInterface(ParsedFile $parsedFile): bool {
        $fileString = "/* Generated automatically by snakedove/php-to-typescript-converter */\n\n";
        $fileString .= 'interface ' . $parsedFile->getClassName();

        if (!empty($parsedFile->getExtends())) {
            $fileString .= ' extends ' . $parsedFile->getExtends();
        }

        $fileString .=' {' . "\n";

        foreach ($parsedFile->getProperties() as $property) {
            $fileString .= "\t" . $property . "\n";
        }

        $fileString .= "}\n";

        return (bool) file_put_contents($this->outputFile, $fileString);
    }
}
