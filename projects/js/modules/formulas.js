/**
 * Formula Calculation Module - COMPLETE
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  // ===== RECALCULATE ALL FORMULAS FOR ITEM =====
  window.BoardApp.recalculateFormulas = function(itemId) {
    console.log('🔢 Recalculating formulas for item:', itemId);
    
    const formulaColumns = window.BOARD_DATA.columns.filter(c => c.type === 'formula');
    
    if (formulaColumns.length === 0) {
      console.log('No formula columns found');
      return;
    }
    
    const itemValues = window.BOARD_DATA.valuesMap[itemId] || {};
    
    // Build column name to ID map
    const colNameMap = {};
    window.BOARD_DATA.columns.forEach(c => {
      colNameMap[c.name] = c.column_id;
    });
    
    // Process formulas in order (to support cascading)
    formulaColumns.forEach(col => {
      const config = col.config ? JSON.parse(col.config) : {};
      const formula = (config.formula || '').trim();
      const precision = parseInt(config.precision) || 2;
      
      if (!formula) {
        console.log('No formula for column:', col.name);
        return;
      }
      
      // Replace column names with values
      let expr = formula;
      
      for (const [name, colId] of Object.entries(colNameMap)) {
        const value = parseFloat(itemValues[colId]) || 0;
        const pattern = new RegExp('\\{' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\}', 'g');
        expr = expr.replace(pattern, value);
      }
      
      // Remove spaces
      expr = expr.replace(/\s+/g, '');
      
      console.log('Formula:', formula, '→ Expression:', expr);
      
      // Calculate result
      let result = 0;
      try {
        if (/^[\d\+\-\*\/\(\)\.]+$/.test(expr)) {
          result = eval(expr);
          if (isNaN(result) || !isFinite(result)) {
            result = 0;
          }
        } else {
          console.warn('Invalid formula expression:', expr);
        }
      } catch (e) {
        console.error('Formula eval error:', e);
        result = 0;
      }
      
      const formatted = Number(result).toFixed(precision);
      
      console.log('Result:', formatted);
      
      // Update cell in DOM
      const cell = document.querySelector(`td[data-item-id="${itemId}"][data-column-id="${col.column_id}"]`);
      if (cell) {
        cell.innerHTML = `<span class="fw-cell-number">${formatted}</span>`;
      }
      
      // Update in memory (for cascading formulas)
      if (!window.BOARD_DATA.valuesMap[itemId]) {
        window.BOARD_DATA.valuesMap[itemId] = {};
      }
      window.BOARD_DATA.valuesMap[itemId][col.column_id] = formatted;
    });
    
    console.log('✅ Formulas recalculated');
  };

  // ===== AUTO-RECALCULATE WHEN DEPENDENCIES CHANGE =====
document.addEventListener('cellUpdated', function(e) {
  const { itemId, columnId, columnType } = e.detail;
  
  // Only trigger for number columns
  if (columnType !== 'number') return;
  
  console.log('📊 Number cell updated, checking formula dependencies...', { itemId, columnId });
  
  // Find formulas that depend on this column
  const updatedColumn = window.BOARD_DATA.columns.find(c => c.column_id == columnId);
  if (!updatedColumn) return;
  
  const dependentFormulas = window.BOARD_DATA.columns.filter(col => {
    if (col.type !== 'formula') return false;
    
    const config = col.config ? JSON.parse(col.config) : {};
    const formula = config.formula || '';
    
    // Check if formula references this column
    return formula.includes(`{${updatedColumn.name}}`);
  });
  
  if (dependentFormulas.length > 0) {
    console.log(`🔄 Found ${dependentFormulas.length} dependent formulas, recalculating...`);
    
    setTimeout(() => {
      if (window.BoardApp.recalculateFormulas) {
        window.BoardApp.recalculateFormulas(itemId);
      }
    }, 100);
  }
});

console.log('✅ Formula auto-recalculate listener registered');

  // ===== RECALCULATE ALL ITEMS =====
  window.BoardApp.recalculateAllFormulas = function() {
    console.log('🔢 Recalculating all formulas...');
    
    window.BOARD_DATA.items.forEach(item => {
      window.BoardApp.recalculateFormulas(item.id);
    });
    
    console.log('✅ All formulas recalculated');
  };

  console.log('✅ Formulas module loaded');

})();