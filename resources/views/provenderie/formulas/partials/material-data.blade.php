{{-- Teneurs d'une matière première, au format attendu par FormulaLab.
     Émis depuis FoodNorm::NUTRIENTS : un nutriment ajouté au référentiel suit
     automatiquement, sur les deux écrans. --}}
@php $labMaterial = $material; @endphp
data-cost="{{ (float) $labMaterial->unit_cost }}"
@foreach(\App\Models\FoodNorm::NUTRIENTS as $labKey => $labNutrient)
data-n-{{ $labKey }}="{{ (float) $labMaterial->{$labNutrient['material']} }}"
@endforeach
