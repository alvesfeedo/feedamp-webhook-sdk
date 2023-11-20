<?php

namespace FeedonomicsWebHookSDK\services;

class OrderUtils
{

    /**
     * @param float $value
     * @param int $number_of_lines
     * @return array
     */
    static public function divide_currency_among_lines(float $value, int $number_of_lines)
    {
        if ($number_of_lines <= 0) {
            return [];
        }

        $line_value = round($value / $number_of_lines, 2);

        $line_discrepancy = round($value - ($line_value * $number_of_lines), 2);
        $penny_counter = abs((int)round($line_discrepancy * 100));
        $modifier = $line_discrepancy > 0 ? 0.01 : -0.01;

        $value_map = [];
        for ($i = 0; $i < $number_of_lines; $i++) {
            $current_line = $line_value;
            if ($penny_counter > 0) {
                $current_line += $modifier;
                $penny_counter--;
            }

            $value_map[$i] = round($current_line, 2);
        }

        return $value_map;
    }
}