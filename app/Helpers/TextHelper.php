<?php

namespace App\Helpers;

class TextHelper
{
    /**
     * Formata nome próprio com primeira letra de cada palavra em maiúscula
     * Mantém preposições e artigos em minúsculo (de, da, dos, das, do, e)
     * Compatível com UTF-8 (funciona corretamente com acentos)
     *
     * @param string|null $nome
     * @return string
     */
    public static function formatarNomeProprio(?string $nome): string
    {
        if (empty($nome)) {
            return '';
        }

        // Garantir UTF-8
        if (!mb_check_encoding($nome, 'UTF-8')) {
            $nome = mb_convert_encoding($nome, 'UTF-8', 'auto');
        }

        // Remover espaços extras
        $nome = trim(preg_replace('/\s+/', ' ', $nome));

        // Converter para minúsculo primeiro
        $nome = mb_strtolower($nome, 'UTF-8');

        // Lista de preposições e artigos que devem ficar em minúsculo
        $minusculas = ['de', 'da', 'do', 'dos', 'das', 'e', 'a', 'o', 'as', 'os'];

        // Separar palavras
        $palavras = explode(' ', $nome);

        // Processar cada palavra
        $palavrasFormatadas = [];
        foreach ($palavras as $index => $palavra) {
            // Primeira palavra sempre em maiúscula, ou se não for preposição/artigo
            if ($index === 0 || !in_array($palavra, $minusculas)) {
                // Usar mb_convert_case para suportar UTF-8 corretamente
                $palavrasFormatadas[] = mb_convert_case($palavra, MB_CASE_TITLE, 'UTF-8');
            } else {
                $palavrasFormatadas[] = $palavra;
            }
        }

        return implode(' ', $palavrasFormatadas);
    }
}
