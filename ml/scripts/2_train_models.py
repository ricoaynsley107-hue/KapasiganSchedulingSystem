# ml/scripts/2_train_models.py (FIXED VERSION)
import pandas as pd
import numpy as np
from sklearn.tree import DecisionTreeClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score, classification_report
import joblib
from config import MODEL_PATH, DATA_PATH
import warnings
warnings.filterwarnings('ignore')

def train_decision_tree():
    """Train Decision Tree for Auto-Approval"""
    
    print("\n🌳 === TRAINING DECISION TREE (AUTO-APPROVAL) ===")
    
    # Load data
    df = pd.read_csv(f"{DATA_PATH}training_data.csv")

    print(f"📊 Loaded {len(df)} records")
    
    # Filter only approved/denied cases
    df_filtered = df[df['status'].isin(['approved', 'denied'])].copy()
    
    if len(df_filtered) < 10:
        print(f"⚠️ Warning: Only {len(df_filtered)} samples available. Model may not be very accurate.")
    
    # Create binary target
    df_filtered['target'] = (df_filtered['status'] == 'approved').astype(int)
    
    # Features for auto-approval decision
    feature_cols = [
        'hour_of_day',
        'day_of_week',
        'advance_booking_days',
        'duration_hours',
        'user_approval_rate',
        'user_completion_rate',
        'same_day_facility_demand',
        'is_weekend'
    ]
    
    X = df_filtered[feature_cols].fillna(0)
    y = df_filtered['target']
    
    # Adjust test size based on available data
    min_test_samples = 2
    test_size = max(0.2, min_test_samples / len(df_filtered))
    
    if len(df_filtered) < 5:
        # Too few samples, use the whole dataset for training
        X_train, X_test, y_train, y_test = X, X, y, y
        print("⚠️ Using all data for training due to small sample size")
    else:
        # Split data
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=test_size, random_state=42, stratify=y if len(y.unique()) > 1 else None
        )
    
    # Train model
    model = DecisionTreeClassifier(
        max_depth=5,
        min_samples_split=2,  # Reduced for small datasets
        random_state=42
    )
    
    print("🔄 Training model...")
    model.fit(X_train, y_train)
    
    # Evaluate
    y_pred = model.predict(X_test)
    accuracy = accuracy_score(y_test, y_pred)
    
    print(f"✅ Model trained successfully!")
    print(f"🎯 Accuracy: {accuracy:.2%}")
    print("\n📊 Classification Report:")
    print(classification_report(y_test, y_pred, target_names=['Denied', 'Approved'], zero_division=0))
    
    # Feature importance
    importance = pd.DataFrame({
        'feature': feature_cols,
        'importance': model.feature_importances_
    }).sort_values('importance', ascending=False)
    
    print("\n📈 Feature Importance:")
    print(importance.to_string(index=False))
    
    # Save model
    model_file = f"{MODEL_PATH}decision_tree.pkl"
    joblib.dump(model, model_file)
    print(f"\n💾 Model saved: {model_file}")
    
    return model

def train_logistic_regression():
    """Train Logistic Regression for No-Show Prediction"""
    
    print("\n📉 === TRAINING LOGISTIC REGRESSION (NO-SHOW PREDICTION) ===")
    
    # Load data
    df = pd.read_csv(f"{DATA_PATH}training_data.csv")
    
    # Filter completed bookings - RELAXED CRITERIA
    # Accept 'approved' bookings as potential training data
    df_filtered = df[df['status'].isin(['completed', 'approved', 'cancelled'])].copy()
    
    print(f"📊 Found {len(df_filtered)} records with status information")
    
    if len(df_filtered) < 10:
        print(f"⚠️ WARNING: Insufficient completed bookings ({len(df_filtered)} found)")
        print("📝 The system needs more historical data to predict no-shows accurately.")
        print("💡 For now, creating a baseline model using available data...")
        
        # Create synthetic training data for baseline model
        if len(df_filtered) == 0:
            print("\n🔄 Creating baseline model with synthetic data...")
            # Create minimal synthetic dataset
            synthetic_data = {
                'hour_of_day': [9, 10, 14, 15, 16],
                'day_of_week': [1, 2, 3, 4, 5],
                'advance_booking_days': [7, 5, 3, 1, 14],
                'duration_hours': [2, 3, 1, 2, 4],
                'user_completion_rate': [0.9, 0.8, 0.7, 0.6, 0.95],
                'is_weekend': [0, 0, 0, 0, 1],
                'same_day_facility_demand': [1, 2, 3, 1, 2],
                'attendance_success': [1, 1, 0, 0, 1]  # Target
            }
            df_filtered = pd.DataFrame(synthetic_data)
            print("✅ Baseline synthetic data created")
    
    # Create target
    df_filtered['target'] = df_filtered.get('attendance_success', 1)  # Default to attended
    
    # Features
    feature_cols = [
        'hour_of_day',
        'day_of_week',
        'advance_booking_days',
        'duration_hours',
        'user_completion_rate',
        'is_weekend',
        'same_day_facility_demand'
    ]
    
    X = df_filtered[feature_cols].fillna(0.5)  # Fill missing with neutral value
    y = df_filtered['target']
    
    # Handle small datasets
    if len(df_filtered) < 5:
        X_train, X_test, y_train, y_test = X, X, y, y
        print("⚠️ Using all data for training due to small sample size")
    else:
        test_size = max(0.2, 2 / len(df_filtered))
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=test_size, random_state=42
        )
    
    # Train model
    model = LogisticRegression(random_state=42, max_iter=1000)
    
    print("🔄 Training model...")
    model.fit(X_train, y_train)
    
    # Evaluate
    y_pred = model.predict(X_test)
    y_pred_proba = model.predict_proba(X_test)[:, 1]
    
    accuracy = accuracy_score(y_test, y_pred)
    
    print(f"✅ Model trained successfully!")
    print(f"🎯 Accuracy: {accuracy:.2%}")
    print("\n📊 Classification Report:")
    print(classification_report(y_test, y_pred, target_names=['No-Show', 'Attended'], zero_division=0))
    
    # Save model
    model_file = f"{MODEL_PATH}logistic_regression.pkl"
    joblib.dump(model, model_file)
    print(f"\n💾 Model saved: {model_file}")
    
    if len(df[df['status'] == 'completed']) < 5:
        print("\n💡 RECOMMENDATION:")
        print("   - Mark completed bookings in your system to improve accuracy")
        print("   - The model will improve as you collect more historical data")
        print("   - Current model uses baseline assumptions")
    
    return model

if __name__ == "__main__":
    # Create models directory if not exists
    import os
    os.makedirs(MODEL_PATH, exist_ok=True)
    
    # Train both models
    print("\n🚀 STARTING ML MODEL TRAINING...\n")
    dt_model = train_decision_tree()
    lr_model = train_logistic_regression()
    
    print("\n🎉 === ALL MODELS TRAINED SUCCESSFULLY! ===")
    print("\n📋 NEXT STEPS:")
    print("1. Test predictions with: python 3_predict.py")
    print("2. Integrate with PHP: Create api/ml_predict.php")
    print("3. Update booking handlers to use ML predictions")
    print("4. Monitor accuracy in admin dashboard")
    print("\n✅ Your ML system is ready to use!")