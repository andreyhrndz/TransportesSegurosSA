const assert = require('assert');
const fs = require('fs');

// Read app.js and extract descomponerNombreCR function
const code = fs.readFileSync('public/assets/js/app.js', 'utf8');
const fnMatch = code.match(/function descomponerNombreCR\([\s\S]*?\n    \}/);
if (!fnMatch) {
    throw new Error('No se encontró descomponerNombreCR en app.js');
}

// Evaluate function in sandbox
const fn = new Function(fnMatch[0] + '; return descomponerNombreCR;')();

console.log('Probando descomponerNombreCR...');

// Caso 1: 4 tokens (Andrey)
const c1 = fn('ANDREY JOSUE TIJERINO HERNANDEZ');
assert.strictEqual(c1.nombre, 'ANDREY JOSUE', 'Caso 1: nombre debe ser ANDREY JOSUE');
assert.strictEqual(c1.primerApellido, 'TIJERINO', 'Caso 1: primerApellido debe ser TIJERINO');
assert.strictEqual(c1.segundoApellido, 'HERNANDEZ', 'Caso 1: segundoApellido debe ser HERNANDEZ');
assert.strictEqual(c1.ambiguo, false, 'Caso 1: no debe ser ambiguo');
console.log('  [OK] ANDREY JOSUE TIJERINO HERNANDEZ ->', c1);

// Caso 2: 3 tokens
const c2 = fn('MARIA LOPEZ MORA');
assert.strictEqual(c2.nombre, 'MARIA');
assert.strictEqual(c2.primerApellido, 'LOPEZ');
assert.strictEqual(c2.segundoApellido, 'MORA');
assert.strictEqual(c2.ambiguo, false);
console.log('  [OK] MARIA LOPEZ MORA ->', c2);

// Caso 3: 4 tokens
const c3 = fn('JUAN CARLOS ALFARO VARGAS');
assert.strictEqual(c3.nombre, 'JUAN CARLOS');
assert.strictEqual(c3.primerApellido, 'ALFARO');
assert.strictEqual(c3.segundoApellido, 'VARGAS');
assert.strictEqual(c3.ambiguo, false);
console.log('  [OK] JUAN CARLOS ALFARO VARGAS ->', c3);

// Caso 4: 5 tokens
const c4 = fn('MARIA DEL CARMEN LOPEZ MORA');
assert.strictEqual(c4.nombre, 'MARIA DEL CARMEN');
assert.strictEqual(c4.primerApellido, 'LOPEZ');
assert.strictEqual(c4.segundoApellido, 'MORA');
assert.strictEqual(c4.ambiguo, false);
console.log('  [OK] MARIA DEL CARMEN LOPEZ MORA ->', c4);

// Caso 5: 2 tokens (ambiguo)
const c5 = fn('MARIA MORA');
assert.strictEqual(c5.nombre, 'MARIA');
assert.strictEqual(c5.primerApellido, 'MORA');
assert.strictEqual(c5.segundoApellido, '');
assert.strictEqual(c5.ambiguo, true);
console.log('  [OK] MARIA MORA (ambiguo) ->', c5);

// Caso 6: 1 token (ambiguo)
const c6 = fn('CORPORACION');
assert.strictEqual(c6.nombre, 'CORPORACION');
assert.strictEqual(c6.primerApellido, '');
assert.strictEqual(c6.segundoApellido, '');
assert.strictEqual(c6.ambiguo, true);
console.log('  [OK] CORPORACION (ambiguo) ->', c6);

console.log('TODAS LAS PRUEBAS DE descomponerNombreCR PASARON CON ÉXITO.');
